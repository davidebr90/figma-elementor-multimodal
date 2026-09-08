<?php

declare(strict_types=1);

namespace FEM\Infrastructure;

/** A bounded structural and semantic validator for the PHP import boundary. */
final class SchemaValidator
{
    public const SUPPORTED_SCHEMA_VERSION = '1.0.0';
    /** @var list<string> */
    private const SUPPORTED_SCHEMA_VERSIONS = ['1.0.0', '1.1.0'];

    /** @var list<string> */
    private const ALLOWED_WIDGETS = ['container', 'heading', 'text-editor', 'button', 'image', 'image-gallery', 'image-carousel', 'nested-accordion', 'icon', 'divider', 'spacer'];

    /** @param array<string,mixed> $document */
    public function assertSupported(array $document): void
    {
        if (($document['kind'] ?? null) !== 'fem.document') {
            throw new \InvalidArgumentException('Unsupported FEM document kind.');
        }
        if (!in_array($document['schemaVersion'] ?? null, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported FEM schema version.');
        }
    }

    /** @param array<string,mixed> $document */
    public function assertValid(array $document): void
    {
        $this->assertSupported($document);
        foreach (['source', 'roots', 'nodes', 'assets', 'editables', 'capabilities', 'revisions', 'integrity'] as $field) {
            if (!array_key_exists($field, $document)) {
                throw new \InvalidArgumentException('FEM document is missing ' . $field . '.');
            }
        }
        $source = $document['source'];
        if (!is_array($source) || ($source['provider'] ?? null) !== 'figma' || !is_string($source['identity'] ?? null) || $source['identity'] === '') {
            throw new \InvalidArgumentException('FEM source identity is invalid.');
        }
        $revisions = $document['revisions'];
        if (!is_array($revisions) || !is_string($revisions['figmaRevision'] ?? null) || trim($revisions['figmaRevision']) === '') {
            throw new \InvalidArgumentException('FEM Figma revision is invalid.');
        }
        $roots = $document['roots'];
        $nodes = $document['nodes'];
        if (!is_array($roots) || $roots === [] || !is_array($nodes) || count($nodes) > 10000) {
            throw new \InvalidArgumentException('FEM roots or nodes are invalid.');
        }
        if (!is_array($document['assets']) || count($document['assets']) > 2000 || !is_array($document['editables']) || !is_array($document['capabilities'])) {
            throw new \InvalidArgumentException('FEM asset count exceeds the limit.');
        }
        $this->assertAdditiveV11Metadata($document);
        $capabilities = $document['capabilities'];
        $capabilityKeys = [];
        foreach ($capabilities as $capability) {
            if (!is_array($capability) || !is_string($capability['nodeId'] ?? null) || !is_string($capability['propertyPath'] ?? null) || $capability['propertyPath'] === '') {
                throw new \InvalidArgumentException('FEM capability is invalid.');
            }
            $capabilityKeys[$capability['nodeId'] . '|' . $capability['propertyPath']] = true;
        }
        $parents = [];
        $reachable = [];
        $visiting = [];
        $walk = function (string $id, int $depth) use (&$walk, &$nodes, &$parents, &$reachable, &$visiting): void {
            if (!isset($nodes[$id])) {
                throw new \InvalidArgumentException('Unknown FEM node reference: ' . $id . '.');
            }
            if ($depth > 256 || isset($visiting[$id])) {
                throw new \InvalidArgumentException('FEM node graph is cyclic or too deep.');
            }
            if (isset($reachable[$id])) {
                return;
            }
            $node = $nodes[$id];
            if (!is_array($node) || ($node['id'] ?? null) !== $id || !is_array($node['children'] ?? null) || count($node['children']) > 2000) {
                throw new \InvalidArgumentException('FEM node ' . $id . ' is invalid.');
            }
            if (!is_string($node['widget'] ?? null) || !in_array($node['widget'], self::ALLOWED_WIDGETS, true)) {
                throw new \InvalidArgumentException('FEM node ' . $id . ' has an unsupported widget.');
            }
            $visiting[$id] = true;
            $reachable[$id] = true;
            foreach ($node['children'] as $child) {
                if (!is_string($child)) {
                    throw new \InvalidArgumentException('FEM child reference is invalid.');
                }
                if (isset($parents[$child]) && $parents[$child] !== $id) {
                    throw new \InvalidArgumentException('FEM node has multiple parents: ' . $child . '.');
                }
                $parents[$child] = $id;
                $walk($child, $depth + 1);
            }
            unset($visiting[$id]);
        };
        foreach ($roots as $root) {
            if (!is_string($root)) {
                throw new \InvalidArgumentException('FEM root reference is invalid.');
            }
            $walk($root, 0);
        }
        if (count($reachable) !== count($nodes)) {
            throw new \InvalidArgumentException('FEM document contains unreachable nodes.');
        }
        $this->assertV11Bindings($document, $reachable);
        foreach ($document['editables'] as $editable) {
            if (!is_array($editable) || !is_string($editable['nodeId'] ?? null) || !isset($reachable[$editable['nodeId']])) {
                throw new \InvalidArgumentException('FEM editable references an unknown node.');
            }
            if (!is_string($editable['propertyPath'] ?? null) || $editable['propertyPath'] === '') {
                throw new \InvalidArgumentException('FEM editable property path is invalid.');
            }
            $key = $editable['nodeId'] . '|' . $editable['propertyPath'];
            if (!isset($capabilityKeys[$key])) {
                throw new \InvalidArgumentException('FEM editable has no capability record.');
            }
        }
        $integrity = $document['integrity'];
        if (!is_array($integrity) || ($integrity['algorithm'] ?? null) !== DocumentIntegrity::ALGORITHM || !preg_match('/^[a-f0-9]{64}$/', (string) ($integrity['contentHash'] ?? ''))) {
            throw new \InvalidArgumentException('FEM integrity metadata is invalid.');
        }
        if (!hash_equals((string) $integrity['contentHash'], DocumentIntegrity::contentHash($document))) {
            throw new \InvalidArgumentException('FEM document integrity does not match its content.');
        }
    }

    /** @param array<string,mixed> $document */
    private function assertAdditiveV11Metadata(array $document): void
    {
        if (($document['schemaVersion'] ?? null) !== '1.1.0') {
            return;
        }
        foreach (['styles', 'responsive'] as $field) {
            if (array_key_exists($field, $document) && !is_array($document[$field])) {
                throw new \InvalidArgumentException('FEM v1.1 ' . $field . ' metadata must be an object.');
            }
        }
        if (isset($document['bindings']) && (!is_array($document['bindings']) || !array_is_list($document['bindings']) || count($document['bindings']) > 10000)) {
            throw new \InvalidArgumentException('FEM v1.1 bindings metadata is invalid.');
        }
    }

    /** @param array<string,mixed> $document @param array<string,bool> $reachable */
    private function assertV11Bindings(array $document, array $reachable): void
    {
        if (($document['schemaVersion'] ?? null) !== '1.1.0' || !isset($document['bindings'])) {
            return;
        }
        $seen = [];
        foreach ($document['bindings'] as $binding) {
            if (!is_array($binding) || !is_string($binding['figmaNodeId'] ?? null) || !is_string($binding['femNodeId'] ?? null) || !isset($reachable[$binding['femNodeId']]) || !is_string($binding['propertyPath'] ?? null) || $binding['propertyPath'] === '' || !in_array($binding['ownership'] ?? null, ['figma', 'wordpress', 'shared'], true) || !preg_match('/^[a-f0-9]{64}$/', (string) ($binding['sourceHash'] ?? ''))) {
                throw new \InvalidArgumentException('FEM v1.1 binding is invalid.');
            }
            $key = $binding['figmaNodeId'] . '|' . $binding['propertyPath'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('FEM v1.1 bindings contain a duplicate property mapping.');
            }
            $seen[$key] = true;
        }
    }
}
