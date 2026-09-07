<?php

declare(strict_types=1);

namespace FEM\Infrastructure;

final class Database
{
    public const SCHEMA_VERSION = 3;

    /** @param object $wpdb WordPress's database adapter. */
    public function __construct(private readonly object $wpdb)
    {
    }

    /** @return list<string> */
    public static function migrationStatements(string $prefix, string $charsetCollate = 'DEFAULT CHARACTER SET utf8mb4'): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('WordPress table prefix contains unsupported characters.');
        }

        return [
            "CREATE TABLE {$prefix}fem_schema_meta (meta_key varchar(64) NOT NULL, meta_value longtext NOT NULL, PRIMARY KEY  (meta_key)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_pairings (pairing_id char(43) NOT NULL, user_id bigint(20) unsigned NOT NULL, code_hash char(64) NOT NULL, challenge_hash char(64) NULL, allowed_scopes longtext NOT NULL, expires_at datetime NOT NULL, attempts tinyint unsigned NOT NULL DEFAULT 0, used_at datetime NULL, locked_at datetime NULL, revoked_at datetime NULL, created_at datetime NOT NULL, PRIMARY KEY  (pairing_id), KEY expires_at (expires_at)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_device_credentials (credential_hash char(64) NOT NULL, pairing_id char(43) NOT NULL, user_id bigint(20) unsigned NOT NULL, device_id varchar(191) NOT NULL, scopes longtext NOT NULL, expires_at datetime NOT NULL, revoked_at datetime NULL, created_at datetime NOT NULL, PRIMARY KEY  (credential_hash), KEY pairing_id (pairing_id), KEY expires_at (expires_at), KEY user_device (user_id, device_id)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_audit (audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, action varchar(100) NOT NULL, actor_user_id bigint(20) unsigned NOT NULL, subject_id varchar(191) NOT NULL, context_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (audit_id), KEY subject_id (subject_id), KEY created_at (created_at)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_designs (design_id char(36) NOT NULL, source_identity varchar(191) NOT NULL, current_revision varchar(191) NULL, schema_version varchar(20) NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (design_id), UNIQUE KEY source_identity (source_identity)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_snapshots (snapshot_id char(36) NOT NULL, design_id char(36) NOT NULL, revision varchar(191) NOT NULL, schema_version varchar(20) NOT NULL, content_hash char(64) NOT NULL, document_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (snapshot_id), UNIQUE KEY design_revision (design_id, revision), KEY design_id (design_id)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_imports (import_id char(32) NOT NULL, user_id bigint(20) unsigned NOT NULL, device_id varchar(191) NOT NULL, manifest_json longtext NOT NULL, document_json longtext NOT NULL, missing_assets_json longtext NOT NULL, created_at datetime NOT NULL, expires_at datetime NOT NULL, committed_at datetime NULL, PRIMARY KEY  (import_id), KEY expires_at (expires_at), KEY user_device (user_id, device_id)) {$charsetCollate} ENGINE=InnoDB;",
            "CREATE TABLE {$prefix}fem_idempotency (principal_id varchar(191) NOT NULL, route varchar(191) NOT NULL, method varchar(10) NOT NULL, idempotency_key varchar(191) NOT NULL, request_hash char(64) NOT NULL, response_json longtext NOT NULL, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (principal_id, route, method, idempotency_key), KEY expires_at (expires_at)) {$charsetCollate} ENGINE=InnoDB;",
        ];
    }

    /** Idempotently applies private-plugin tables through WordPress dbDelta. */
    public function migrate(): void
    {
        if (!defined('ABSPATH') || !function_exists('dbDelta') && !is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            throw new \RuntimeException('WordPress migration APIs are unavailable.');
        }
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $prefix = (string) ($this->wpdb->prefix ?? '');
        $charsetCollate = method_exists($this->wpdb, 'get_charset_collate') ? (string) $this->wpdb->get_charset_collate() : '';
        foreach (self::migrationStatements($prefix, $charsetCollate) as $statement) {
            dbDelta($statement);
        }
        foreach (['schema_meta', 'pairings', 'device_credentials', 'audit', 'designs', 'snapshots', 'imports', 'idempotency'] as $table) {
            $name = $this->table($table);
            $exists = $this->wpdb->get_var($this->prepare('SHOW TABLES LIKE %s', [$name]));
            if ($exists !== $name) {
                throw new \RuntimeException('Database migration did not create ' . $name . '.');
            }
        }
        $this->verifySchema($prefix);
        if (function_exists('update_option')) {
            update_option('fem_schema_version', (string) self::SCHEMA_VERSION, false);
        }
    }

    public function pruneAudit(\DateTimeImmutable $now): int
    {
        $cutoff = $now->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s');
        $deleted = $this->query($this->prepare('DELETE FROM ' . $this->table('audit') . ' WHERE created_at < %s', [$cutoff]));
        if ($deleted === false) {
            throw new \RuntimeException('Could not prune expired audit events.');
        }
        return $deleted;
    }

    public function pruneExpiredState(\DateTimeImmutable $now): void
    {
        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        foreach (['imports', 'idempotency', 'device_credentials', 'pairings'] as $table) {
            $deleted = $this->query($this->prepare('DELETE FROM ' . $this->table($table) . ' WHERE expires_at < %s', [$timestamp]));
            if ($deleted === false) {
                throw new \RuntimeException('Could not prune expired ' . $table . '.');
            }
        }
    }

    private function verifySchema(string $prefix): void
    {
        $expected = [
            'schema_meta' => ['meta_key'], 'pairings' => ['pairing_id', 'revoked_at'],
            'device_credentials' => ['credential_hash', 'device_id', 'revoked_at'], 'audit' => ['audit_id', 'created_at'],
            'designs' => ['design_id'], 'snapshots' => ['snapshot_id'], 'imports' => ['import_id', 'expires_at'], 'idempotency' => ['principal_id'],
        ];
        foreach ($expected as $table => $columns) {
            foreach ($columns as $column) {
                $value = $this->wpdb->get_var('SHOW COLUMNS FROM ' . $prefix . 'fem_' . $table . " LIKE '" . $column . "'");
                if ($value !== $column) {
                    throw new \RuntimeException('Migration missing column ' . $table . '.' . $column . '.');
                }
            }
        }
    }

    public function table(string $suffix): string
    {
        if (preg_match('/^[a-z_]+$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('Unsupported FEM table name.');
        }
        return (string) $this->wpdb->prefix . 'fem_' . $suffix;
    }

    /** @param list<mixed> $arguments */
    public function prepare(string $query, array $arguments): string
    {
        return (string) $this->wpdb->prepare($query, ...$arguments);
    }

    public function query(string $query): int|false
    {
        return $this->wpdb->query($query);
    }

    /** @return array<string,mixed>|null */
    public function row(string $query): ?array
    {
        $row = $this->wpdb->get_row($query, \ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
