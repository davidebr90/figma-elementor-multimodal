<?php

declare(strict_types=1);

namespace FEM\Application;

use DateTimeImmutable;
use DateTimeZone;
use FEM\Infrastructure\Database;

/** Durable private staging store. Large import payloads never live in wp_options. */
final class WpImportStore implements ImportStore
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(ImportState $state): void
    {
        $table = $this->database->table('imports');
        $query = $this->database->prepare(
            "INSERT INTO {$table} (import_id,user_id,device_id,manifest_json,document_json,missing_assets_json,created_at,expires_at) VALUES (%s,%d,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE manifest_json = VALUES(manifest_json), document_json = VALUES(document_json), missing_assets_json = VALUES(missing_assets_json), expires_at = VALUES(expires_at)",
            [$state->id, $state->userId, $state->deviceId, json_encode($state->manifest, JSON_THROW_ON_ERROR), json_encode($state->document, JSON_THROW_ON_ERROR), json_encode($state->missingAssets, JSON_THROW_ON_ERROR), $this->utc($state->createdAt), $this->utc($state->expiresAt)]
        );
        if ($this->database->query($query) === false) {
            throw new \RuntimeException('Could not persist staged import.');
        }
    }

    public function find(string $importId): ?ImportState
    {
        $table = $this->database->table('imports');
        $row = $this->database->row($this->database->prepare("SELECT * FROM {$table} WHERE import_id = %s", [$importId]));
        if ($row === null) {
            return null;
        }
        try {
            $manifest = json_decode((string) $row['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
            $document = json_decode((string) $row['document_json'], true, 512, JSON_THROW_ON_ERROR);
            $missing = json_decode((string) $row['missing_assets_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || !is_array($document) || !is_array($missing)) {
                return null;
            }
            return new ImportState((string) $row['import_id'], (int) $row['user_id'], (string) $row['device_id'], $manifest, $document, array_values(array_filter($missing, 'is_string')), $this->date((string) $row['created_at']), $this->date((string) $row['expires_at']));
        } catch (\Exception) {
            return null;
        }
    }

    public function markCommitted(string $importId): void
    {
        $table = $this->database->table('imports');
        $query = $this->database->prepare("UPDATE {$table} SET committed_at = UTC_TIMESTAMP() WHERE import_id = %s", [$importId]);
        if ($this->database->query($query) === false) {
            throw new \RuntimeException('Could not finalize staged import.');
        }
    }

    public function delete(string $importId): void
    {
        $table = $this->database->table('imports');
        $query = $this->database->prepare("DELETE FROM {$table} WHERE import_id = %s", [$importId]);
        if ($this->database->query($query) === false) {
            throw new \RuntimeException('Could not remove expired import.');
        }
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
