<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\Api\RestController;
use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase
{
    public function testImportRouteAcceptsTheRawJsonBodyWithoutARequiredBodyParameter(): void
    {
        $route = RestController::importRouteDefinition();

        self::assertSame('POST', $route['methods']);
        self::assertArrayNotHasKey('args', $route);
    }
}
