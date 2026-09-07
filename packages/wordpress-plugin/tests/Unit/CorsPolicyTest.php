<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Api\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsPolicyTest extends TestCase
{
    public function testFigmaPluginRoutesUseWildcardCorsWithoutCookieCredentials(): void
    {
        $headers = (new CorsPolicy())->headersFor('/figma-elementor-multimodal/v1/imports');

        self::assertSame('*', $headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    public function testOtherWordPressRoutesKeepTheirOwnCorsPolicy(): void
    {
        self::assertSame([], (new CorsPolicy())->headersFor('/wp/v2/posts'));
    }
}
