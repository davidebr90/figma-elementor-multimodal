<?php

declare(strict_types=1);

namespace FEM\Api;

final class RequestLimitException extends \InvalidArgumentException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

final class RequestLimits
{
    public const JSON_BODY_BYTES = 10485760;
    public const ASSET_BYTES = 52428800;
    public const MAX_NODES = 10000;
    public const MAX_DEPTH = 64;
    public const MAX_CHILDREN = 2000;
    public const MAX_STRING_LENGTH = 1000;
    public const MAX_TEXT_LENGTH = 100000;
    public const MAX_ASSETS = 2000;
    public const MAX_IMAGE_DIMENSION = 10000;
    public const MAX_IMAGE_PIXELS = 40000000;

    /** @return array<string,mixed> */
    public function decodeJson(string $body): array
    {
        if (strlen($body) > self::JSON_BODY_BYTES) {
            throw new RequestLimitException(413, 'max-bytes', 'JSON body exceeds the 10 MiB limit.');
        }
        try {
            $value = json_decode($body, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new RequestLimitException(413, 'max-depth', 'JSON nesting exceeds the limit.', $exception);
            }
            throw new RequestLimitException(422, 'invalid-json', 'Request body is not valid JSON.', $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RequestLimitException(422, 'invalid-json-root', 'JSON body must be an object.');
        }
        $document = $value['document'] ?? null;
        if (is_array($document)) {
            if (is_array($document['nodes'] ?? null) && count($document['nodes']) > self::MAX_NODES) {
                throw new RequestLimitException(413, 'max-nodes', 'IR node count exceeds the limit.');
            }
            if (is_array($document['assets'] ?? null) && count($document['assets']) > self::MAX_ASSETS) {
                throw new RequestLimitException(413, 'max-assets', 'IR asset count exceeds the limit.');
            }
        }
        $this->inspect($value, 0, '/');
        return $value;
    }

    public function assertAsset(string $bytes, string $expectedSha256, string $declaredMime): void
    {
        if (strlen($bytes) > self::ASSET_BYTES) {
            throw new RequestLimitException(413, 'max-asset-bytes', 'Asset exceeds the 50 MiB limit.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            throw new RequestLimitException(422, 'invalid-checksum', 'Asset checksum must be lowercase SHA-256.');
        }
        if (!hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RequestLimitException(422, 'checksum-mismatch', 'Asset checksum does not match the request.');
        }
        $magic = ['image/png' => "\x89PNG\x0D\x0A\x1A\x0A", 'image/jpeg' => "\xFF\xD8\xFF"];
        if (!isset($magic[$declaredMime]) || !str_starts_with($bytes, $magic[$declaredMime])) {
            throw new RequestLimitException(415, 'unsupported-media', 'Only verified PNG and JPEG assets are accepted.');
        }
        $dimensions = @getimagesizefromstring($bytes);
        if (!is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > self::MAX_IMAGE_DIMENSION || $dimensions[1] > self::MAX_IMAGE_DIMENSION) {
            throw new RequestLimitException(422, 'invalid-image', 'Image dimensions are invalid or exceed the limit.');
        }
        if ($dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS) {
            throw new RequestLimitException(422, 'max-image-pixels', 'Image pixel count exceeds the 40 megapixel limit.');
        }
    }

    private function inspect(mixed $value, int $depth, string $path): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RequestLimitException(413, 'max-depth', 'JSON nesting exceeds the limit.');
        }
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length > $this->stringLimit($path)) {
                throw new RequestLimitException(413, 'max-string-length', 'A string exceeds the limit at ' . $path . '.');
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (array_is_list($value) && str_ends_with($path, '/children') && count($value) > self::MAX_CHILDREN) {
            throw new RequestLimitException(413, 'max-array-length', 'An array exceeds the limit at ' . $path . '.');
        }
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $length = function_exists('mb_strlen') ? mb_strlen($key, 'UTF-8') : strlen($key);
                if ($length > self::MAX_STRING_LENGTH) {
                    throw new RequestLimitException(413, 'max-string-length', 'An object key exceeds the limit at ' . $path . '.');
                }
            }
            $this->inspect($child, $depth + 1, $path . '/' . (string) $key);
        }
    }

    private function stringLimit(string $path): int
    {
        return str_ends_with($path, '/characters') ? self::MAX_TEXT_LENGTH : self::MAX_STRING_LENGTH;
    }
}
