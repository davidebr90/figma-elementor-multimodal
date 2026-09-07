<?php

declare(strict_types=1);

namespace FEM\Tests\Integration;

use FEM\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigrationStatementsCoverVersionedPrivateTables(): void
    {
        $statements = Database::migrationStatements('wp_');

        self::assertCount(8, $statements);
        self::assertStringContainsString('wp_fem_pairings', implode("\n", $statements));
        self::assertStringContainsString('wp_fem_device_credentials', implode("\n", $statements));
        self::assertStringContainsString('wp_fem_schema_meta', implode("\n", $statements));
        self::assertStringContainsString('wp_fem_imports', implode("\n", $statements));
    }

    public function testMigrationVersionIsStableAndPrefixedTableNamesAreSafe(): void
    {
        self::assertSame(3, Database::SCHEMA_VERSION);
        self::assertStringContainsString('custom_fem_audit', Database::migrationStatements('custom_')[3]);
    }

    public function testVersionThreeRequiresInnoDbAndConcurrencyIndexes(): void
    {
        $statements = implode("\n", Database::migrationStatements('wp_'));

        self::assertSame(8, substr_count($statements, 'ENGINE=InnoDB'));
        self::assertStringContainsString('KEY user_device (user_id, device_id)', $statements);
        self::assertStringContainsString('KEY created_at (created_at)', $statements);
    }

    public function testMigrationPlanIsIdempotentForTheSamePrefix(): void
    {
        self::assertSame(
            Database::migrationStatements('wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),
            Database::migrationStatements('wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        );
    }
}
