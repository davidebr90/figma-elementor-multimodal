<?php

declare(strict_types=1);

namespace FEM\Infrastructure;

use DateTimeImmutable;
use FEM\Security\DeviceCredentialRecord;
use FEM\Security\PairingException;
use FEM\Security\PairingRecord;

final class AuditEvent
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $action,
        public readonly int $actorUserId,
        public readonly string $subjectId,
        public readonly array $context,
    ) {
    }
}

interface PairingRepository
{
    public function save(PairingRecord $record): void;

    /** Atomically checks, counts and consumes a pairing code. */
    public function consume(string $id, string $codeHash, string $challengeHash, DateTimeImmutable $now, int $maxAttempts): ?PairingRecord;

    public function revoke(string $id, DateTimeImmutable $now): void;
}

interface CredentialRepository
{
    public function save(DeviceCredentialRecord $record): void;

    public function findByHash(string $credentialHash): ?DeviceCredentialRecord;

    public function revokeByPairingId(string $pairingId, DateTimeImmutable $now): void;

    public function revokeByUserAndDevice(int $userId, string $deviceId, DateTimeImmutable $now): void;

    /** Atomically replaces the active bearer for one local site/user/device. */
    public function replaceForUserDevice(DeviceCredentialRecord $record, DateTimeImmutable $now): void;
}

interface AuditRepository
{
    /** @param array<string,mixed> $context */
    public function record(string $action, int $actorUserId, string $subjectId, array $context): void;
}

final class InMemoryPairingRepository implements PairingRepository
{
    /** @var array<string,PairingRecord> */
    private array $records = [];

    public function save(PairingRecord $record): void
    {
        $this->records[$record->id] = $record;
    }

    public function consume(string $id, string $codeHash, string $challengeHash, DateTimeImmutable $now, int $maxAttempts): ?PairingRecord
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return null;
        }
        if ($record->lockedAt !== null) {
            throw new PairingException('locked_pairing', 'Pairing is locked.');
        }
        if ($record->revokedAt !== null) {
            throw new PairingException('revoked_pairing', 'Pairing is revoked.');
        }
        if ($record->usedAt !== null) {
            throw new PairingException('used_pairing', 'Pairing code was already used.');
        }
        if ($record->expiresAt <= $now) {
            throw new PairingException('expired_pairing', 'Pairing code is expired.');
        }
        if (!hash_equals($record->codeHash, $codeHash)) {
            $record->attempts++;
            if ($record->attempts >= $maxAttempts) {
                $record->lockedAt = $now;
            }
            return null;
        }

        $record->usedAt = $now;
        $record->challengeHash = $challengeHash;
        return $record;
    }

    public function record(string $id): PairingRecord
    {
        return $this->records[$id];
    }

    public function revoke(string $id, DateTimeImmutable $now): void
    {
        if (isset($this->records[$id])) {
            $this->records[$id]->revokedAt = $now;
        }
    }
}

final class InMemoryCredentialRepository implements CredentialRepository
{
    /** @var array<string,DeviceCredentialRecord> */
    private array $records = [];

    public function save(DeviceCredentialRecord $record): void
    {
        $this->records[$record->credentialHash] = $record;
    }

    public function findByHash(string $credentialHash): ?DeviceCredentialRecord
    {
        return $this->records[$credentialHash] ?? null;
    }

    public function revokeByPairingId(string $pairingId, DateTimeImmutable $now): void
    {
        foreach ($this->records as $record) {
            if ($record->pairingId === $pairingId) {
                $record->revokedAt = $now;
            }
        }
    }

    public function revokeByUserAndDevice(int $userId, string $deviceId, DateTimeImmutable $now): void
    {
        foreach ($this->records as $record) {
            if ($record->userId === $userId && hash_equals($record->deviceId, $deviceId)) {
                $record->revokedAt = $now;
            }
        }
    }

    public function replaceForUserDevice(DeviceCredentialRecord $record, DateTimeImmutable $now): void
    {
        $this->revokeByUserAndDevice($record->userId, $record->deviceId, $now);
        $this->save($record);
    }
}

final class InMemoryAuditRepository implements AuditRepository
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function record(string $action, int $actorUserId, string $subjectId, array $context): void
    {
        $this->events[] = new AuditEvent($action, $actorUserId, $subjectId, $context);
    }

    /** @return list<AuditEvent> */
    public function events(): array
    {
        return $this->events;
    }
}

/** Persistence contracts used by import/application code, never by $wpdb consumers. */
interface DesignRepository
{
    public function upsert(string $designId, string $sourceIdentity, string $schemaVersion, ?string $revision, DateTimeImmutable $now): void;
}

interface SnapshotRepository
{
    /** @param array<string,mixed> $document */
    public function append(string $snapshotId, string $designId, string $revision, string $schemaVersion, string $contentHash, array $document, DateTimeImmutable $now): void;

    /** @return array{snapshotId:string,contentHash:string}|null */
    public function findByRevision(string $designId, string $revision): ?array;
}

interface IdempotencyRepository
{
    /** @return array<string,mixed>|null */
    public function find(string $principalId, string $route, string $method, string $key, DateTimeImmutable $now): ?array;

    /** @param array<string,mixed> $response */
    /** Returns false when another request has already claimed this idempotency key. */
    public function store(string $principalId, string $route, string $method, string $key, string $requestHash, array $response, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool;
}

/** Deterministic in-memory implementation for application-level tests. */
final class InMemoryIdempotencyRepository implements IdempotencyRepository
{
    /** @var array<string,array{requestHash:string,response:array<string,mixed>,expiresAt:DateTimeImmutable}> */
    private array $records = [];

    public function find(string $principalId, string $route, string $method, string $key, DateTimeImmutable $now): ?array
    {
        $record = $this->records[$this->recordKey($principalId, $route, $method, $key)] ?? null;
        if ($record === null || $record['expiresAt'] <= $now) {
            return null;
        }
        return ['requestHash' => $record['requestHash'], 'response' => $record['response']];
    }

    public function store(string $principalId, string $route, string $method, string $key, string $requestHash, array $response, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        $recordKey = $this->recordKey($principalId, $route, $method, $key);
        if (isset($this->records[$recordKey]) && $this->records[$recordKey]['expiresAt'] > $now) {
            return false;
        }
        $this->records[$recordKey] = ['requestHash' => $requestHash, 'response' => $response, 'expiresAt' => $expiresAt];
        return true;
    }

    private function recordKey(string $principalId, string $route, string $method, string $key): string
    {
        return hash('sha256', $principalId . "\n" . $route . "\n" . $method . "\n" . $key);
    }
}

/** WordPress adapter; domain services only receive the repository interfaces above. */
final class WpPairingRepository implements PairingRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(PairingRecord $record): void
    {
        $table = $this->database->table('pairings');
        $query = $this->database->prepare(
            "INSERT INTO {$table} (pairing_id,user_id,code_hash,allowed_scopes,expires_at,attempts,created_at) VALUES (%s,%d,%s,%s,%s,0,%s)",
            [$record->id, $record->userId, $record->codeHash, json_encode($record->allowedScopes, JSON_THROW_ON_ERROR), $record->expiresAt->format('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        if ($this->database->query($query) !== 1) {
            throw new \RuntimeException('Could not persist pairing.');
        }
    }

    public function consume(string $id, string $codeHash, string $challengeHash, DateTimeImmutable $now, int $maxAttempts): ?PairingRecord
    {
        $table = $this->database->table('pairings');
        $row = $this->database->row($this->database->prepare("SELECT * FROM {$table} WHERE pairing_id = %s", [$id]));
        if ($row === null) {
            return null;
        }
        $record = $this->record($row);
        if ($record->lockedAt !== null) {
            throw new PairingException('locked_pairing', 'Pairing is locked.');
        }
        if ($record->usedAt !== null) {
            throw new PairingException('used_pairing', 'Pairing code was already used.');
        }
        if ($record->expiresAt <= $now) {
            throw new PairingException('expired_pairing', 'Pairing code is expired.');
        }

        $timestamp = $now->format('Y-m-d H:i:s');
        if (!hash_equals($record->codeHash, $codeHash)) {
            $query = $this->database->prepare(
                "UPDATE {$table} SET attempts = attempts + 1, locked_at = CASE WHEN attempts + 1 >= %d THEN %s ELSE locked_at END WHERE pairing_id = %s AND used_at IS NULL AND locked_at IS NULL AND revoked_at IS NULL AND expires_at > %s AND code_hash <> %s",
                [$maxAttempts, $timestamp, $id, $timestamp, $codeHash]
            );
            $this->database->query($query);
            return null;
        }

        $query = $this->database->prepare(
            "UPDATE {$table} SET used_at = %s, challenge_hash = %s WHERE pairing_id = %s AND code_hash = %s AND used_at IS NULL AND locked_at IS NULL AND revoked_at IS NULL AND expires_at > %s",
            [$timestamp, $challengeHash, $id, $codeHash, $timestamp]
        );
        if ($this->database->query($query) !== 1) {
            // Do not reveal whether a concurrent request consumed or locked it.
            throw new PairingException('invalid_pairing_code', 'Invalid pairing code.');
        }
        $record->usedAt = $now;
        $record->challengeHash = $challengeHash;
        return $record;
    }

    /** @param array<string,mixed> $row */
    private function record(array $row): PairingRecord
    {
        $scopes = json_decode((string) $row['allowed_scopes'], true, 512, JSON_THROW_ON_ERROR);
        return new PairingRecord(
            (string) $row['pairing_id'],
            (int) $row['user_id'],
            (string) $row['code_hash'],
            new DateTimeImmutable((string) $row['expires_at']),
            is_array($scopes) ? array_values($scopes) : [],
            (int) $row['attempts'],
            $row['used_at'] === null ? null : new DateTimeImmutable((string) $row['used_at']),
            $row['locked_at'] === null ? null : new DateTimeImmutable((string) $row['locked_at']),
            $row['challenge_hash'] === null ? null : (string) $row['challenge_hash'],
            $row['revoked_at'] === null ? null : new DateTimeImmutable((string) $row['revoked_at']),
        );
    }

    public function revoke(string $id, DateTimeImmutable $now): void
    {
        $table = $this->database->table('pairings');
        $this->database->query($this->database->prepare("UPDATE {$table} SET revoked_at = %s WHERE pairing_id = %s AND revoked_at IS NULL", [$now->format('Y-m-d H:i:s'), $id]));
    }
}

final class WpCredentialRepository implements CredentialRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(DeviceCredentialRecord $record): void
    {
        $table = $this->database->table('device_credentials');
        $query = $this->database->prepare(
            "INSERT INTO {$table} (credential_hash,pairing_id,user_id,device_id,scopes,expires_at,created_at) VALUES (%s,%s,%d,%s,%s,%s,%s)",
            [$record->credentialHash, $record->pairingId, $record->userId, $record->deviceId, json_encode($record->scopes, JSON_THROW_ON_ERROR), $record->expiresAt->format('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
        );
        if ($this->database->query($query) !== 1) {
            throw new \RuntimeException('Could not persist device credential.');
        }
    }

    public function findByHash(string $credentialHash): ?DeviceCredentialRecord
    {
        $table = $this->database->table('device_credentials');
        $row = $this->database->row($this->database->prepare("SELECT * FROM {$table} WHERE credential_hash = %s", [$credentialHash]));
        if ($row === null) {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true, 512, JSON_THROW_ON_ERROR);
        return new DeviceCredentialRecord((string) $row['credential_hash'], (string) $row['pairing_id'], (int) $row['user_id'], (string) $row['device_id'], is_array($scopes) ? array_values($scopes) : [], new DateTimeImmutable((string) $row['expires_at']), $row['revoked_at'] === null ? null : new DateTimeImmutable((string) $row['revoked_at']));
    }

    public function revokeByPairingId(string $pairingId, DateTimeImmutable $now): void
    {
        $table = $this->database->table('device_credentials');
        $this->database->query($this->database->prepare("UPDATE {$table} SET revoked_at = %s WHERE pairing_id = %s AND revoked_at IS NULL", [$now->format('Y-m-d H:i:s'), $pairingId]));
    }

    public function revokeByUserAndDevice(int $userId, string $deviceId, DateTimeImmutable $now): void
    {
        $table = $this->database->table('device_credentials');
        $this->database->query($this->database->prepare("UPDATE {$table} SET revoked_at = %s WHERE user_id = %d AND device_id = %s AND revoked_at IS NULL", [$now->format('Y-m-d H:i:s'), $userId, $deviceId]));
    }

    public function replaceForUserDevice(DeviceCredentialRecord $record, DateTimeImmutable $now): void
    {
        $table = $this->database->table('device_credentials');
        // Serializes replacement for this site-local user/device tuple.
        $this->database->query('START TRANSACTION');
        try {
            $this->database->query($this->database->prepare("SELECT credential_hash FROM {$table} WHERE user_id = %d AND device_id = %s FOR UPDATE", [$record->userId, $record->deviceId]));
            $this->revokeByUserAndDevice($record->userId, $record->deviceId, $now);
            $this->save($record);
            $this->database->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');
            throw $exception;
        }
    }
}

final class WpAuditRepository implements AuditRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(string $action, int $actorUserId, string $subjectId, array $context): void
    {
        $table = $this->database->table('audit');
        // Bound private-test audit retention on every write; cron handles idle sites.
        $this->database->pruneAudit(new DateTimeImmutable('now'));
        $query = $this->database->prepare("INSERT INTO {$table} (action,actor_user_id,subject_id,context_json,created_at) VALUES (%s,%d,%s,%s,%s)", [$action, $actorUserId, $subjectId, json_encode($context, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
        if ($this->database->query($query) !== 1) {
            throw new \RuntimeException('Could not persist audit event.');
        }
    }
}

final class WpDesignRepository implements DesignRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function upsert(string $designId, string $sourceIdentity, string $schemaVersion, ?string $revision, DateTimeImmutable $now): void
    {
        $table = $this->database->table('designs');
        $timestamp = $now->format('Y-m-d H:i:s');
        $query = $this->database->prepare(
            "INSERT INTO {$table} (design_id,source_identity,current_revision,schema_version,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE current_revision = VALUES(current_revision), schema_version = VALUES(schema_version), updated_at = VALUES(updated_at)",
            [$designId, $sourceIdentity, $revision, $schemaVersion, $timestamp, $timestamp]
        );
        if ($this->database->query($query) === false) {
            throw new \RuntimeException('Could not save design metadata.');
        }
    }
}

final class WpSnapshotRepository implements SnapshotRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function append(string $snapshotId, string $designId, string $revision, string $schemaVersion, string $contentHash, array $document, DateTimeImmutable $now): void
    {
        $table = $this->database->table('snapshots');
        $query = $this->database->prepare(
            "INSERT INTO {$table} (snapshot_id,design_id,revision,schema_version,content_hash,document_json,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)",
            [$snapshotId, $designId, $revision, $schemaVersion, $contentHash, json_encode($document, JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s')]
        );
        if ($this->database->query($query) !== 1) {
            throw new \RuntimeException('Could not save immutable snapshot.');
        }
    }

    /** @return array{snapshotId:string,contentHash:string}|null */
    public function findByRevision(string $designId, string $revision): ?array
    {
        $table = $this->database->table('snapshots');
        $row = $this->database->row($this->database->prepare("SELECT snapshot_id,content_hash FROM {$table} WHERE design_id = %s AND revision = %s LIMIT 1", [$designId, $revision]));
        if ($row === null) {
            return null;
        }
        return ['snapshotId' => (string) $row['snapshot_id'], 'contentHash' => (string) $row['content_hash']];
    }

    /** @return array<string,mixed>|null */
    public function findLatest(string $designId): ?array
    {
        $table = $this->database->table('snapshots');
        $row = $this->database->row($this->database->prepare("SELECT snapshot_id,content_hash,document_json FROM {$table} WHERE design_id = %s ORDER BY created_at DESC LIMIT 1", [$designId]));
        if ($row === null) {
            return null;
        }
        try {
            $document = json_decode((string) $row['document_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($document)) {
            return null;
        }
        return ['snapshotId' => (string) $row['snapshot_id'], 'contentHash' => (string) $row['content_hash'], 'document' => $document];
    }
}

final class WpIdempotencyRepository implements IdempotencyRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function find(string $principalId, string $route, string $method, string $key, DateTimeImmutable $now): ?array
    {
        $table = $this->database->table('idempotency');
        $row = $this->database->row($this->database->prepare("SELECT request_hash,response_json FROM {$table} WHERE principal_id = %s AND route = %s AND method = %s AND idempotency_key = %s AND expires_at > %s", [$principalId, $route, $method, $key, $now->format('Y-m-d H:i:s')]));
        if ($row === null) {
            return null;
        }
        $response = json_decode((string) $row['response_json'], true, 512, JSON_THROW_ON_ERROR);
        return ['requestHash' => (string) $row['request_hash'], 'response' => is_array($response) ? $response : []];
    }

    public function store(string $principalId, string $route, string $method, string $key, string $requestHash, array $response, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        $table = $this->database->table('idempotency');
        $query = $this->database->prepare(
            "INSERT IGNORE INTO {$table} (principal_id,route,method,idempotency_key,request_hash,response_json,expires_at,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
            [$principalId, $route, $method, $key, $requestHash, json_encode($response, JSON_THROW_ON_ERROR), $expiresAt->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]
        );
        $written = $this->database->query($query);
        if ($written === false) {
            throw new \RuntimeException('Could not save idempotency record.');
        }
        return $written === 1;
    }
}
