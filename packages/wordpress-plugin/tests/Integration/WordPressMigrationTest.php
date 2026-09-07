<?php

declare(strict_types=1);

namespace FEM\Tests\Integration;

use FEM\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class WordPressMigrationTest extends TestCase
{
    public function testMigrationIsIdempotentAgainstARealWordPressDatabase(): void
    {
        $testsDir = getenv('FEM_WP_TESTS_DIR');
        if ($testsDir !== false && !function_exists('dbDelta') && is_file($testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php')) {
            require_once $testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php';
        }
        if ($testsDir === false || !function_exists('dbDelta') || !isset($GLOBALS['wpdb'])) {
            self::markTestSkipped('Requires a WordPress integration harness and MySQL; SQL-string tests are not a substitute.');
        }

        $database = new Database($GLOBALS['wpdb']);
        $database->migrate();
        $database->migrate();

        self::assertSame((string) Database::SCHEMA_VERSION, get_option('fem_schema_version'));
    }
}
