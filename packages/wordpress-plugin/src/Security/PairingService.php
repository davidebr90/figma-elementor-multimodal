<?php

declare(strict_types=1);

namespace FEM\Security;

use DateInterval;
use DateTimeImmutable;
use FEM\Infrastructure\AuditRepository;
use FEM\Infrastructure\CredentialRepository;
use FEM\Infrastructure\PairingRepository;

interface Clock
{
    public function now(): DateTimeImmutable;
}

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

/** A deterministic clock for isolated tests. */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}

interface RandomSource
{
    public function bytes(int $length): string;
}

final class SystemRandomSource implements RandomSource
{
    public function bytes(int $length): string
    {
        return random_bytes($length);
    }
}

/** A deterministic entropy source for tests; it must never be used in a request. */
final class SequenceRandomSource implements RandomSource
{
    private int $counter = 0;

    public function __construct(private readonly string $seed)
    {
    }

    public function bytes(int $length): string
    {
        $output = '';
        while (strlen($output) < $length) {
            $output .= hash('sha256', $this->seed . ':' . $this->counter++, true);
        }

        return substr($output, 0, $length);
    }
}

final class PairingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

final class PairingCode
{
    /** @param list<string> $allowedScopes */
    public function __construct(
        public readonly string $pairingId,
        public readonly string $code,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $allowedScopes,
    ) {
    }
}

final class DeviceCredential
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $credential,
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $scopes,
        public readonly string $siteId,
    ) {
    }
}

final class AuthenticatedDevice
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly array $scopes,
        public readonly string $pairingId,
    ) {
    }
}

final class PairingRecord
{
    /** @param list<string> $allowedScopes */
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly string $codeHash,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $allowedScopes,
        public int $attempts = 0,
        public ?DateTimeImmutable $usedAt = null,
        public ?DateTimeImmutable $lockedAt = null,
        public ?string $challengeHash = null,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
    }
}

final class DeviceCredentialRecord
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $credentialHash,
        public readonly string $pairingId,
        public readonly int $userId,
        public readonly string $deviceId,
        public readonly array $scopes,
        public readonly DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
    }
}

final class PairingService
{
    private const CODE_TTL = 'PT10M';
    private const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    private const ALLOWED_SCOPES = ['import:write', 'asset:write', 'changes:read', 'changes:ack'];

    public function __construct(
        private readonly PairingRepository $pairings,
        private readonly CredentialRepository $credentials,
        private readonly AuditRepository $audit,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly string $hmacKey,
        // Bearer credentials are short-lived, but long enough for a normal design session.
        private readonly DateInterval $credentialTtl = new DateInterval('PT8H'),
        private readonly string $siteId = 'wordpress-site',
    ) {
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('Pairing HMAC key must not be empty.');
        }
    }

    public function create(int $userId): PairingCode
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A positive WordPress user ID is required.');
        }

        $now = $this->clock->now();
        $pairingId = $this->token(16);
        $code = strtoupper(substr($this->token(12), 0, 12));
        $expiresAt = $now->add(new DateInterval(self::CODE_TTL));
        $this->pairings->save(new PairingRecord(
            $pairingId,
            $userId,
            $this->hash($code),
            $expiresAt,
            self::ALLOWED_SCOPES,
        ));
        $this->audit->record('pairing.created', $userId, $pairingId, ['expiresAt' => $expiresAt->format(DATE_ATOM)]);

        return new PairingCode($pairingId, $code, $expiresAt, self::ALLOWED_SCOPES);
    }

    /** @param list<string> $scopes */
    public function exchange(string $pairingId, string $code, string $deviceId, string $challenge, array $scopes): DeviceCredential
    {
        if ($pairingId === '' || $code === '' || $deviceId === '' || $challenge === '') {
            throw new PairingException('invalid_pairing_request', 'Pairing ID, code, device and challenge are required.');
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, self::ALLOWED_SCOPES) !== []) {
            throw new PairingException('invalid_scope', 'Requested scope is not allowed.');
        }

        $record = $this->pairings->consume(
            $pairingId,
            $this->hash($code),
            $this->hash($challenge),
            $this->clock->now(),
            self::MAX_ATTEMPTS,
        );
        if ($record === null) {
            throw new PairingException('invalid_pairing_code', 'Invalid pairing code.');
        }
        if (array_diff($scopes, $record->allowedScopes) !== []) {
            throw new PairingException('invalid_scope', 'Requested scope is not allowed for this pairing.');
        }

        $rawCredential = $this->token(32);
        $expiresAt = $this->clock->now()->add($this->credentialTtl);
        // One repository operation maintains the site/user/device bearer invariant.
        $this->credentials->replaceForUserDevice(new DeviceCredentialRecord(
            $this->hash($rawCredential),
            $record->id,
            $record->userId,
            $deviceId,
            $scopes,
            $expiresAt,
        ), $this->clock->now());
        $this->audit->record('pairing.exchanged', $record->userId, $record->id, ['deviceId' => $deviceId, 'scopes' => $scopes]);

        return new DeviceCredential($rawCredential, $record->userId, $deviceId, $expiresAt, $scopes, $this->siteId);
    }

    public function authenticate(string $credential, string $scope): AuthenticatedDevice
    {
        $record = $credential === '' ? null : $this->credentials->findByHash($this->hash($credential));
        if ($record === null) {
            throw new PairingException('invalid_credential', 'Invalid device credential.');
        }
        if ($record->revokedAt !== null) {
            throw new PairingException('revoked_credential', 'Device credential is revoked.');
        }
        if ($record->expiresAt <= $this->clock->now()) {
            throw new PairingException('expired_credential', 'Device credential is expired.');
        }
        if (!in_array($scope, $record->scopes, true)) {
            throw new PairingException('insufficient_scope', 'Device credential lacks the required scope.');
        }

        return new AuthenticatedDevice($record->userId, $record->deviceId, $record->scopes, $record->pairingId);
    }

    public function renew(string $credential): DeviceCredential
    {
        $record = $credential === '' ? null : $this->credentials->findByHash($this->hash($credential));
        if ($record === null || $record->revokedAt !== null) {
            throw new PairingException('invalid_credential', 'Invalid device credential.');
        }
        if ($record->expiresAt <= $this->clock->now()) {
            throw new PairingException('expired_credential', 'Device credential is expired; pair Figma again.');
        }

        $rawCredential = $this->token(32);
        $expiresAt = $this->clock->now()->add($this->credentialTtl);
        $this->credentials->replaceForUserDevice(new DeviceCredentialRecord(
            $this->hash($rawCredential),
            $record->pairingId,
            $record->userId,
            $record->deviceId,
            $record->scopes,
            $expiresAt,
        ), $this->clock->now());
        $this->audit->record('pairing.renewed', $record->userId, $record->pairingId, ['deviceId' => $record->deviceId, 'scopes' => $record->scopes]);

        return new DeviceCredential($rawCredential, $record->userId, $record->deviceId, $expiresAt, $record->scopes, $this->siteId);
    }

    public function revokePairing(string $pairingId, int $actorUserId): void
    {
        $now = $this->clock->now();
        $this->pairings->revoke($pairingId, $now);
        $this->credentials->revokeByPairingId($pairingId, $now);
        $this->audit->record('pairing.revoked', $actorUserId, $pairingId, []);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hmacKey);
    }

    private function token(int $bytes): string
    {
        return rtrim(strtr(base64_encode($this->random->bytes($bytes)), '+/', '-_'), '=');
    }
}
