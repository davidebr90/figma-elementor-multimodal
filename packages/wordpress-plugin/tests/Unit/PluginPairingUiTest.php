<?php

declare(strict_types=1);

namespace FEM;

function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

namespace FEM\Tests\Unit;

use FEM\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginPairingUiTest extends TestCase
{
    public function testPairingFieldContainsItsValueAndAccessibleCopyControl(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'pairingField');
        $method->setAccessible(true);

        $html = $method->invoke(null, 'WordPress site URL', 'fem-site-url', 'http://localhost:8096');

        self::assertStringContainsString('id="fem-site-url"', $html);
        self::assertStringContainsString('value="http://localhost:8096"', $html);
        self::assertStringContainsString('data-target="fem-site-url"', $html);
        self::assertStringContainsString('Copy', $html);
        self::assertStringContainsString('aria-label="Copy WordPress site URL"', $html);
    }
}
