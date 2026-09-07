<?php

declare(strict_types=1);

namespace FEM\Infrastructure;

/** Cross-runtime canonical JSON hash used by the Figma exporter and import boundary. */
final class DocumentIntegrity
{
    public const ALGORITHM = 'sha256-jcs';

    /** @param array<string,mixed> $document */
    public static function contentHash(array $document): string
    {
        if (!is_array($document['integrity'] ?? null)) {
            throw new \InvalidArgumentException('FEM integrity metadata is invalid.');
        }
        $canonical = $document;
        $canonical['integrity']['contentHash'] = str_repeat('0', 64);
        return hash('sha256', self::json($canonical, ''));
    }

    private static function json(mixed $value, string $path): string
    {
        if (is_array($value)) {
            // json_decode(..., true) represents both {} and [] as an empty PHP
            // array. FEM's schema tells us which empty values are lists; all
            // other empty values retain the object form emitted by Figma.
            if (self::isListPath($path) || $value !== [] && array_is_list($value)) {
                $items = [];
                foreach ($value as $index => $item) {
                    $items[] = self::json($item, $path . '/' . $index);
                }
                return '[' . implode(',', $items) . ']';
            }
            ksort($value, SORT_STRING);
            $entries = [];
            foreach ($value as $key => $item) {
                $entries[] = self::scalar((string) $key) . ':' . self::json($item, $path . '/' . $key);
            }
            return '{' . implode(',', $entries) . '}';
        }
        return self::scalar($value);
    }

    private static function isListPath(string $path): bool
    {
        return in_array($path, ['/roots', '/editables', '/capabilities', '/warnings', '/unsupported'], true) || str_ends_with($path, '/children');
    }

    private static function scalar(mixed $value): string
    {
        if (is_float($value) && $value == 0.0) {
            return '0';
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('FEM document cannot be canonicalized.', 0, $exception);
        }
    }
}
