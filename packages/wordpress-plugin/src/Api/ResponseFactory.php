<?php

declare(strict_types=1);

namespace FEM\Api;

final class RestResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data, private readonly int $status)
    {
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match WP_REST_Response's public interface.
    public function get_status(): int
    {
        return $this->status;
    }
    /** @return array<string,mixed> */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match WP_REST_Response's public interface.
    public function get_data(): array
    {
        return $this->data;
    }
}

final class ResponseFactory
{
    /** @param array<string,mixed> $data @param list<array<string,mixed>> $warnings */
    public function success(array $data, string $requestId, ?string $revision = null, array $warnings = []): array
    {
        return ['success' => true, 'data' => $data, 'warnings' => $warnings, 'errors' => [], 'meta' => $this->meta($requestId, $revision)];
    }

    /** @param string|null $path */
    public function error(int $status, string $code, string $message, string $requestId, ?string $path = null, ?int $retryAfter = null): object
    {
        $error = ['code' => $code, 'message' => $message];
        if ($path !== null) {
            $error['path'] = $path;
        }
        if ($retryAfter !== null) {
            $error['retryAfter'] = $retryAfter;
        }
        $body = ['success' => false, 'data' => [], 'warnings' => [], 'errors' => [$error], 'meta' => $this->meta($requestId, null)];
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($body, $status);
        }
        return new RestResponse($body, $status);
    }

    /** @return array<string,string> */
    private function meta(string $requestId, ?string $revision): array
    {
        $meta = ['requestId' => $requestId, 'schemaVersion' => '1.0.0', 'protocolVersion' => '1.0.0'];
        if ($revision !== null) {
            $meta['revision'] = $revision;
        }
        return $meta;
    }
}
