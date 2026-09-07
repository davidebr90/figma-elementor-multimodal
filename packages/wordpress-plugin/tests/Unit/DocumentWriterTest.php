<?php

declare(strict_types=1);

use FEM\Elementor\DocumentWriter;
use PHPUnit\Framework\TestCase;

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false): mixed { return $GLOBALS['fem_test_post_meta'][$postId][$key] ?? ''; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): void { $GLOBALS['fem_test_post_meta'][$postId][$key] = $value; }
}
if (!function_exists('wp_slash')) {
    function wp_slash(string $value): string { return addslashes($value); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false { return json_encode($value); }
}

final class DocumentWriterTest extends TestCase
{
    protected function setUp(): void { $GLOBALS['fem_test_post_meta'] = []; }

    public function testMalformedExistingElementorDataFailsClosed(): void
    {
        $GLOBALS['fem_test_post_meta'][12]['_elementor_data'] = '{broken';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        (new DocumentWriter())->write(12, [['id' => 'new']], false);
    }

    public function testReplaceIgnoresMalformedExistingDataByExplicitRequest(): void
    {
        $GLOBALS['fem_test_post_meta'][12]['_elementor_data'] = '{broken';
        self::assertSame(1, (new DocumentWriter())->write(12, [['id' => 'new']], true));
    }

    public function testAppendRemapsCollidingElementIds(): void
    {
        $GLOBALS['fem_test_post_meta'][12]['_elementor_data'] = json_encode([['id' => 'abc1234', 'elements' => []]]);

        self::assertSame(2, (new DocumentWriter())->write(12, [['id' => 'abc1234', 'elements' => []]], false));
        $saved = json_decode(stripslashes((string) $GLOBALS['fem_test_post_meta'][12]['_elementor_data']), true);
        self::assertNotSame('abc1234', $saved[1]['id']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{7}$/', $saved[1]['id']);
    }
}
