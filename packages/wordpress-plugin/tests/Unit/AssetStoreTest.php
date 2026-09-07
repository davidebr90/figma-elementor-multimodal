<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Infrastructure\InMemoryAssetStore;
use PHPUnit\Framework\TestCase;

final class AssetStoreTest extends TestCase
{
    public function testInMemoryStoreRejectsUnsupportedMimeEvenWhenCalledDirectly(): void
    {
        $bytes = 'asset-bytes';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported asset MIME');
        (new InMemoryAssetStore())->put(hash('sha256', $bytes), $bytes, 'application/octet-stream');
    }
}
