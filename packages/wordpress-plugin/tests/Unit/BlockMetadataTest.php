<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BlockMetadataTest extends TestCase
{
    public function testSceneDeclaresTheIdentityAttributesConsumedByItsRenderer(): void
    {
        /** @var array<string,mixed> $metadata */
        $metadata = json_decode((string) file_get_contents(__DIR__ . '/../../blocks/fem-scene/block.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('string', $metadata['attributes']['femId']['type']);
        self::assertSame('string', $metadata['attributes']['schemaVersion']['type']);
        self::assertSame('string', $metadata['attributes']['sourceHash']['type']);
    }
}
