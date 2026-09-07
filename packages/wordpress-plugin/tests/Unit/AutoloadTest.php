<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Application\WpImportStore;
use FEM\Infrastructure\InMemoryAssetStore;
use FEM\Infrastructure\WpAssetStore;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testFallbackAutoloadLoadsClassesFromGroupedInfrastructureFiles(): void
    {
        self::assertTrue(class_exists(InMemoryAssetStore::class));
        self::assertTrue(class_exists(WpAssetStore::class));
    }

    public function testWordPressImportStoreHasItsOwnPsr4File(): void
    {
        $expected = realpath(dirname(__DIR__, 2) . '/src/Application/WpImportStore.php');

        self::assertNotFalse($expected);
        self::assertSame($expected, (new \ReflectionClass(WpImportStore::class))->getFileName());
    }
}
