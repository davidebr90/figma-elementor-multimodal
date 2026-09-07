<?php

declare(strict_types=1);

namespace FEM\Elementor;

use FEM\WordPress\CurrentUserScope;

/** Writes imported elements through Elementor's public document API when present. */
final class DocumentWriter
{
    public function __construct(private readonly CurrentUserScope $users = new CurrentUserScope())
    {
    }

    /** @param list<array<string,mixed>> $elements */
    public function write(int $pageId, array $elements, bool $replace, int $userId = 0): int
    {
        $existing = get_post_meta($pageId, '_elementor_data', true);
        $tree = $this->decodeExisting($existing, $replace);
        $tree = is_array($tree) && !$replace ? $tree : [];
        $elements = $replace ? $elements : $this->avoidIdCollisions($tree, $elements);
        $tree = array_merge($tree, $elements);

        if (class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->documents)) {
            $document = \Elementor\Plugin::$instance->documents->get($pageId);
            if (is_object($document) && method_exists($document, 'save')) {
                $this->users->runAs($userId, static function () use ($document, $tree): void {
                    if ($document->save(['elements' => $tree]) === false) {
                        throw new \RuntimeException('Elementor refused to save the document for the paired user.');
                    }
                });
                $this->clearCache();
                return count($tree);
            }
        }

        update_post_meta($pageId, '_elementor_data', wp_slash((string) wp_json_encode($tree)));
        update_post_meta($pageId, '_elementor_edit_mode', 'builder');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($pageId, '_elementor_version', ELEMENTOR_VERSION);
        }
        $this->clearCache();
        return count($tree);
    }

    /** @return array<mixed>|null */
    private function decodeExisting(mixed $existing, bool $replace): ?array
    {
        if ($replace || $existing === '' || $existing === null) {
            return null;
        }
        if (is_array($existing)) {
            return $existing;
        }
        if (!is_string($existing)) {
            throw new \RuntimeException('Elementor document data has an unsupported format.');
        }
        try {
            $decoded = json_decode($existing, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Elementor document data is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Elementor document data must be a JSON array.');
        }
        return $decoded;
    }

    /** @param array<mixed> $existing @param list<array<string,mixed>> $elements @return list<array<string,mixed>> */
    private function avoidIdCollisions(array $existing, array $elements): array
    {
        $used = [];
        $this->collectElementIds($existing, $used);
        foreach ($elements as &$element) {
            $this->remapElementIds($element, $used);
        }
        unset($element);
        return $elements;
    }

    /** @param array<mixed> $tree @param array<string,bool> $used */
    private function collectElementIds(array $tree, array &$used): void
    {
        foreach ($tree as $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['id']) && is_string($value['id']) && preg_match('/^[a-z0-9]{7}$/', $value['id']) === 1) {
                $used[$value['id']] = true;
            }
            if (isset($value['elements']) && is_array($value['elements'])) {
                $this->collectElementIds($value['elements'], $used);
            }
        }
    }

    /** @param array<string,mixed> $element @param array<string,bool> $used */
    private function remapElementIds(array &$element, array &$used): void
    {
        if (isset($element['id']) && is_string($element['id']) && preg_match('/^[a-z0-9]{7}$/', $element['id']) === 1) {
            $id = $element['id'];
            if (isset($used[$id])) {
                do {
                    $id = substr(bin2hex(random_bytes(4)), 0, 7);
                } while (isset($used[$id]));
                $element['id'] = $id;
            }
            $used[$id] = true;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                if (is_array($child)) {
                    $this->remapElementIds($child, $used);
                }
            }
            unset($child);
        }
    }

    private function clearCache(): void
    {
        if (!class_exists('Elementor\\Plugin')) {
            return;
        }
        $plugin = \Elementor\Plugin::$instance ?? null;
        if (is_object($plugin) && isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
            $plugin->files_manager->clear_cache();
        }
    }
}
