<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Api\CapabilityContract;
use PHPUnit\Framework\TestCase;

final class CapabilityContractTest extends TestCase
{
    public function testAdvertisedContractContainsOnlySupportedImportCapabilities(): void
    {
        $contract = CapabilityContract::advertised();

        self::assertSame(['1.0.0', '1.1.0'], $contract['supportedSchemaVersions']);
        self::assertSame(['elementor', 'wordpress-blocks'], $contract['targets']);
        self::assertNotContains('wordpress-to-figma', $contract['features']);
    }
}
