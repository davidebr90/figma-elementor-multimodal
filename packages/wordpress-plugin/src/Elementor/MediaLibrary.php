<?php

declare(strict_types=1);

namespace FEM\Elementor;

/**
 * Turns a staged FEM asset into a real media library attachment.
 *
 * The asset store keeps bytes under an opaque ".bin" name, which Elementor's
 * image widget cannot use: it needs an attachment ID. Attachments are cached per
 * checksum, so re-importing the same design reuses the existing media item
 * instead of filling the library with duplicates.
 */
final class MediaLibrary
{
    private const ASSET_OPTION_PREFIX = 'fem_asset_';
    private const ATTACHMENT_OPTION_PREFIX = 'fem_attachment_';

    /** @return array{id:int,url:string}|null */
    public function attachmentFor(string $sha256): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !function_exists('get_option')) {
            return null;
        }
        $cached = get_option(self::ATTACHMENT_OPTION_PREFIX . $sha256, false);
        if (is_array($cached) && isset($cached['id']) && get_post((int) $cached['id']) !== null) {
            return ['id' => (int) $cached['id'], 'url' => (string) ($cached['url'] ?? wp_get_attachment_url((int) $cached['id']))];
        }
        $stored = $this->storedFile($sha256);
        if ($stored === null) {
            return null;
        }
        // The asset store already wrote a real .png/.jpg into uploads, so the
        // attachment can point at that file instead of duplicating the bytes.
        if (in_array(strtolower(pathinfo($stored['path'], PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) {
            return $this->attachExisting($sha256, $stored['path'], $stored['url']);
        }
        $bytes = file_get_contents($stored['path']);
        return is_string($bytes) ? $this->createAttachment($sha256, $bytes) : null;
    }

    /** @return array{path:string,url:string}|null */
    private function storedFile(string $sha256): ?array
    {
        $asset = get_option(self::ASSET_OPTION_PREFIX . $sha256, false);
        if (!is_array($asset) || !is_string($asset['url'] ?? null) || $asset['url'] === '') {
            return null;
        }
        $uploads = wp_upload_dir();
        $path = str_replace($uploads['baseurl'], $uploads['basedir'], $asset['url']);
        return is_file($path) ? ['path' => $path, 'url' => $asset['url']] : null;
    }

    /** @return array{id:int,url:string}|null */
    private function attachExisting(string $sha256, string $path, string $url): ?array
    {
        $mime = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title' => 'FEM ' . substr($sha256, 0, 12),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $path);
        if ($attachmentId <= 0) {
            return null;
        }
        $this->generateMetadata($attachmentId, $path);
        update_option(self::ATTACHMENT_OPTION_PREFIX . $sha256, ['id' => $attachmentId, 'url' => $url], false);
        return ['id' => $attachmentId, 'url' => $url];
    }

    private function generateMetadata(int $attachmentId, string $path): void
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $path));
    }

    /** @return array{id:int,url:string}|null */
    private function createAttachment(string $sha256, string $bytes): ?array
    {
        if (!function_exists('wp_upload_bits') || !function_exists('wp_insert_attachment')) {
            return null;
        }
        $upload = wp_upload_bits('fem-' . substr($sha256, 0, 16) . '.png', null, $bytes);
        if (!empty($upload['error'])) {
            return null;
        }
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'FEM ' . substr($sha256, 0, 12),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);
        if ($attachmentId <= 0) {
            return null;
        }
        if (function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $upload['file']));
        }
        $url = $upload['url'];
        update_option(self::ATTACHMENT_OPTION_PREFIX . $sha256, ['id' => $attachmentId, 'url' => $url], false);
        return ['id' => $attachmentId, 'url' => $url];
    }
}
