<?php

declare(strict_types=1);

namespace FEM\Api;

/** CORS policy for bearer-authenticated Figma plugin requests. */
final class CorsPolicy
{
    /** @return array<string,string> */
    public function headersFor(string $route): array
    {
        if (!str_starts_with($route, '/figma-elementor-multimodal/v1/')) {
            return [];
        }
        // Figma plugin iframes have a `null` origin and require `*`. FEM never
        // relies on cookies: every protected call uses an expiring bearer token.
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => 'ETag',
        ];
    }
}
