<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use FEM\WordPress\CurrentUserScope;
use PHPUnit\Framework\TestCase;

final class CurrentUserScopeTest extends TestCase
{
    public function testItRunsCallbackAsPairedUserAndRestoresPreviousUser(): void
    {
        $current = 3;
        $scope = new CurrentUserScope(static function () use (&$current): int {
            return $current;
        }, static function (int $userId) use (&$current): void {
            $current = $userId;
        });

        $seen = $scope->runAs(17, static function () use (&$current): int {
            return $current;
        });

        self::assertSame(17, $seen);
        self::assertSame(3, $current);
    }

    public function testItRestoresPreviousUserWhenTheCallbackFails(): void
    {
        $current = 3;
        $scope = new CurrentUserScope(static function () use (&$current): int {
            return $current;
        }, static function (int $userId) use (&$current): void {
            $current = $userId;
        });

        try {
            $scope->runAs(17, static function (): void {
                throw new \RuntimeException('stop');
            });
            self::fail('The callback exception should be preserved.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stop', $exception->getMessage());
        }

        self::assertSame(3, $current);
    }
}
