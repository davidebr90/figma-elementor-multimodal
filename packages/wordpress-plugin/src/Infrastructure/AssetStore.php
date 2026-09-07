<?php

declare(strict_types=1);

namespace FEM\Infrastructure;

final class AssetReceipt
{
    public function __construct(public readonly string $sha256, public readonly string $mime, public readonly int $byteLength)
    {
    }
}

interface AssetStore
{
    public function put(string $sha256, string $bytes, string $mime): AssetReceipt;
    public function has(string $sha256): bool;
}

final class InMemoryAssetStore implements AssetStore
{
    /** @var list<string> */
    private const SUPPORTED_MIME = ['image/png', 'image/jpeg'];

    /** @var array<string,AssetReceipt> */
    private array $assets = [];

    public function put(string $sha256, string $bytes, string $mime): AssetReceipt
    {
        if (!in_array(strtolower(trim($mime)), self::SUPPORTED_MIME, true)) {
            throw new \InvalidArgumentException('Unsupported asset MIME.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('Asset checksum does not match payload.');
        }
        $receipt = new AssetReceipt($sha256, $mime, strlen($bytes));
        $this->assets[$sha256] = $receipt;
        return $receipt;
    }

    public function has(string $sha256): bool
    {
        return isset($this->assets[$sha256]);
    }
}

final class WpAssetStore implements AssetStore
{
    private const OPTION_PREFIX = 'fem_asset_';
    /** @var list<string> */
    private const SUPPORTED_MIME = ['image/png', 'image/jpeg'];

    public function put(string $sha256, string $bytes, string $mime): AssetReceipt
    {
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::SUPPORTED_MIME, true)) {
            throw new \InvalidArgumentException('Unsupported asset MIME.');
        }
        if (!function_exists('wp_upload_bits') || !function_exists('update_option')) {
            throw new \RuntimeException('WordPress upload APIs are unavailable.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('Asset checksum does not match payload.');
        }
        $existing = get_option(self::OPTION_PREFIX . $sha256, false);
        if (is_array($existing)) {
            return new AssetReceipt($sha256, (string) ($existing['mime'] ?? $mime), (int) ($existing['byteLength'] ?? strlen($bytes)));
        }
        // WordPress refuses unknown extensions, so ".bin" was always rejected.
        // The MIME is already verified upstream, so the real extension is safe.
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
        };
        $upload = wp_upload_bits('fem-' . $sha256 . '.' . $extension, null, $bytes);
        if (!empty($upload['error'])) {
            throw new \RuntimeException('Could not persist asset: ' . $upload['error']);
        }
        $receipt = new AssetReceipt($sha256, $mime, strlen($bytes));
        update_option(self::OPTION_PREFIX . $sha256, ['mime' => $receipt->mime, 'byteLength' => $receipt->byteLength, 'url' => $upload['url']], false);
        return $receipt;
    }

    public function has(string $sha256): bool
    {
        return function_exists('get_option') && is_array(get_option(self::OPTION_PREFIX . $sha256, false));
    }
}
