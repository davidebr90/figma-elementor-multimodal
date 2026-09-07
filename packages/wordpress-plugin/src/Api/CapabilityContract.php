<?php

declare(strict_types=1);

namespace FEM\Api;

final class CapabilityContract
{
    /** @return array<string,mixed> */
    public static function advertised(): array
    {
        return [
            'protocolVersion' => '1.0.0',
            'supportedSchemaVersions' => ['1.0.0', '1.1.0'],
            'targets' => ['elementor', 'wordpress-blocks'],
            'features' => ['responsive-overrides', 'validated-assets', 'bindings-readonly'],
        ];
    }
}
