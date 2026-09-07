<?php

declare(strict_types=1);

namespace FEM\Rendering;

/** Stable, editor-neutral identity shared by all FEM renderers. */
final class ClassMap
{
    /** @param array<string,mixed> $document */
    public function designClass(array $document): string
    {
        return 'fem-design-' . substr(hash('sha256', (string) ($document['source']['identity'] ?? 'unknown')), 0, 12);
    }

    /** @param array<string,mixed> $node */
    public function nodeClass(array $node): string
    {
        return 'fem-node-' . substr(hash('sha256', (string) ($node['id'] ?? 'unknown')), 0, 12);
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $node */
    public function classes(array $document, array $node, string $role = ''): string
    {
        $classes = [$this->designClass($document), $this->nodeClass($node)];
        if ($role !== '') {
            $safeRole = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($role)) ?: 'node';
            $classes[] = 'fem-role-' . trim($safeRole, '-');
        }
        return implode(' ', $classes);
    }
}
