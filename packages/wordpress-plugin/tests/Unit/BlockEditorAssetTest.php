<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BlockEditorAssetTest extends TestCase
{
    public function testSceneMetadataRegistersTheEditorScript(): void
    {
        /** @var array<string,mixed> $metadata */
        $metadata = json_decode((string) file_get_contents(__DIR__ . '/../../blocks/fem-scene/block.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('file:./editor.js', $metadata['editorScript']);
        self::assertFileExists(__DIR__ . '/../../blocks/fem-scene/editor.js');
        self::assertStringNotContainsString('eval(', (string) file_get_contents(__DIR__ . '/../../blocks/fem-scene/editor.js'));
    }
}
