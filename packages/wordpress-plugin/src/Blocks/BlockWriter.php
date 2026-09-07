<?php

declare(strict_types=1);

namespace FEM\Blocks;

/** Writes serialized blocks through the normal WordPress post API. */
final class BlockWriter
{
    /** @param array<string,mixed> $document @return array{added:int,total:int,notes:list<string>} */
    public function write(int $pageId, array $document, bool $replace): array
    {
        $rendered = (new BlockRenderer())->render($document);
        if ($rendered['content'] === '') {
            throw new \RuntimeException('The FEM document produced no native blocks.');
        }
        $post = function_exists('get_post') ? get_post($pageId) : null;
        if (!is_object($post)) {
            throw new \OutOfRangeException('Page was not found.');
        }
        $content = $replace ? $rendered['content'] : trim((string) $post->post_content) . "\n" . $rendered['content'];
        if (!function_exists('wp_update_post')) {
            throw new \RuntimeException('WordPress post API is unavailable.');
        }
        $updated = wp_update_post(wp_slash(['ID' => $pageId, 'post_content' => $content]), true);
        if (is_wp_error($updated)) {
            throw new \RuntimeException($updated->get_error_message());
        }
        return ['added' => substr_count($rendered['content'], '<!-- wp:'), 'total' => substr_count($content, '<!-- wp:'), 'notes' => $rendered['notes']];
    }
}
