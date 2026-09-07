<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Blocks\BlockRenderer;
use FEM\Blocks\BlockMediaResolver;
use FEM\Rendering\ClassMap;
use PHPUnit\Framework\TestCase;

final class BlockRendererTest extends TestCase
{
    public function testImageNodesUseARealNativeImageBlockWhenTheAssetIsAvailable(): void
    {
        $renderer = new BlockRenderer(new ClassMap(), new class implements BlockMediaResolver {
            public function resolve(string $sha256): ?array
            {
                return $sha256 === str_repeat('a', 64) ? ['id' => 91, 'url' => 'https://example.test/uploads/image.png'] : null;
            }
        });

        $result = $renderer->render(['roots' => ['image'], 'nodes' => [
            'image' => ['id' => 'image', 'widget' => 'image', 'children' => [], 'image' => ['sha256' => str_repeat('a', 64)], 'content' => ['name' => 'Product image']],
        ]]);

        self::assertStringContainsString('<!-- wp:core/image', $result['content']);
        self::assertStringContainsString('https://example.test/uploads/image.png', $result['content']);
        self::assertSame([], $result['notes']);
    }

    public function testUntrustedIdentityCannotEscapeBlockComment(): void
    {
        $id = 'node--><script>alert(1)</script><!--';
        $result = (new BlockRenderer())->render(['roots' => [$id], 'nodes' => [
            $id => ['id' => $id, 'widget' => 'text-editor', 'children' => [], 'text' => ['characters' => '<script>bad</script>']],
        ]]);
        self::assertStringNotContainsString('<script>', $result['content']);
        self::assertStringContainsString('\\u003c', $result['content']);
    }

    public function testCycleIsRejectedBeforeRendering(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BlockRenderer())->render(['roots' => ['a'], 'nodes' => [
            'a' => ['id' => 'a', 'children' => ['a']],
        ]]);
    }

    public function testRendersNativeBlocksWithStableNamespacedIdentity(): void
    {
        $document = [
            'source' => ['identity' => 'figma:file:node'],
            'roots' => ['root'],
            'nodes' => [
                'root' => ['id' => 'root', 'widget' => 'container', 'children' => ['title', 'copy'], 'responsive' => ['mobile' => ['padding' => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8]]]],
                'title' => ['id' => 'title', 'widget' => 'heading', 'children' => [], 'text' => ['characters' => 'Hello', 'fontSize' => 42]],
                'copy' => ['id' => 'copy', 'widget' => 'text-editor', 'children' => [], 'text' => ['characters' => 'Safe & stable']],
            ],
        ];

        $result = (new BlockRenderer())->render($document);

        self::assertStringContainsString('<!-- wp:core/group', $result['content']);
        self::assertStringContainsString('<!-- wp:core/heading', $result['content']);
        self::assertStringContainsString('fem-design-', $result['content']);
        self::assertStringContainsString('fem-node-', $result['content']);
        self::assertStringContainsString('"@mobile"', $result['content']);
        self::assertStringContainsString('Safe &amp; stable', $result['content']);
    }
}
