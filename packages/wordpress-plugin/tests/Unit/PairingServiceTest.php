<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use FEM\Infrastructure\InMemoryAuditRepository;
use FEM\Infrastructure\InMemoryCredentialRepository;
use FEM\Infrastructure\InMemoryPairingRepository;
use FEM\Security\FrozenClock;
use FEM\Security\PairingException;
use FEM\Security\PairingService;
use FEM\Security\SequenceRandomSource;
use PHPUnit\Framework\TestCase;

final class PairingServiceTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryPairingRepository $pairings;
    private InMemoryCredentialRepository $credentials;
    private InMemoryAuditRepository $audit;
    private PairingService $service;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-09-04T10:00:00+00:00'));
        $this->pairings = new InMemoryPairingRepository();
        $this->credentials = new InMemoryCredentialRepository();
        $this->audit = new InMemoryAuditRepository();
        $this->service = new PairingService(
            $this->pairings,
            $this->credentials,
            $this->audit,
            $this->clock,
            new SequenceRandomSource('test-seed'),
            'unit-test-hmac-key',
            new DateInterval('PT15M')
        );
    }

    public function testExchangeConsumesACodeAndStoresOnlyHashes(): void
    {
        $pairing = $this->service->create(42);

        $credential = $this->service->exchange(
            $pairing->pairingId,
            $pairing->code,
            'figma-device-1',
            'challenge-1',
            ['import:write']
        );

        self::assertSame(42, $credential->userId);
        self::assertSame(['import:write'], $credential->scopes);
        self::assertNotSame($pairing->code, $this->pairings->record($pairing->pairingId)->codeHash);
        self::assertNotSame('challenge-1', $this->pairings->record($pairing->pairingId)->challengeHash);
        self::assertNotNull($this->credentials->findByHash(hash_hmac('sha256', $credential->credential, 'unit-test-hmac-key')));
        self::assertSame('pairing.exchanged', $this->audit->events()[1]->action);
    }

    public function testDefaultCredentialSessionLastsEightHours(): void
    {
        $service = new PairingService($this->pairings, $this->credentials, $this->audit, $this->clock, new SequenceRandomSource('default-ttl'), 'unit-test-hmac-key');
        $pairing = $service->create(42);
        $credential = $service->exchange($pairing->pairingId, $pairing->code, 'figma-device', 'challenge', ['import:write']);

        self::assertSame('2026-09-04T18:00:00+00:00', $credential->expiresAt->format(DATE_ATOM));
    }

    public function testRenewalRotatesCredentialAndKeepsScopes(): void
    {
        $pairing = $this->service->create(42);
        $old = $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
        $this->clock->advance(new DateInterval('PT5M'));

        $new = $this->service->renew($old->credential);

        self::assertNotSame($old->credential, $new->credential);
        self::assertSame(['import:write'], $new->scopes);
        self::assertSame('2026-09-04T10:20:00+00:00', $new->expiresAt->format(DATE_ATOM));
        try {
            $this->service->authenticate($old->credential, 'import:write');
            self::fail('The previous bearer must be revoked after renewal.');
        } catch (PairingException $exception) {
            self::assertSame('revoked_credential', $exception->errorCode);
        }
        self::assertSame(42, $this->service->authenticate($new->credential, 'import:write')->userId);
    }

    public function testExpiredCredentialCannotBeRenewed(): void
    {
        $pairing = $this->service->create(42);
        $credential = $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
        $this->clock->advance(new DateInterval('PT15M1S'));

        $this->expectExceptionMessage('expired');
        $this->service->renew($credential->credential);
    }

    public function testWrongCodeIsRejectedAndFiveAttemptsLockThePairing(): void
    {
        $pairing = $this->service->create(42);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->service->exchange($pairing->pairingId, 'wrong-code', 'device', 'challenge', ['import:write']);
                self::fail('An invalid pairing code must not issue a credential.');
            } catch (PairingException $exception) {
                self::assertSame('invalid_pairing_code', $exception->errorCode);
            }
        }

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('locked');
        $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
    }

    public function testExpiredPairingCannotBeExchanged(): void
    {
        $pairing = $this->service->create(42);
        $this->clock->advance(new DateInterval('PT10M1S'));

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('expired');
        $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
    }

    public function testRevokedCredentialCannotAuthenticate(): void
    {
        $pairing = $this->service->create(42);
        $credential = $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
        $this->service->revokePairing($pairing->pairingId, 42);

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('revoked');
        $this->service->authenticate($credential->credential, 'import:write');
    }

    public function testRevokedPairingCannotBeExchanged(): void
    {
        $pairing = $this->service->create(42);
        $this->service->revokePairing($pairing->pairingId, 42);

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('revoked');
        $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
    }

    public function testRePairingDeviceRevokesItsPreviousCredential(): void
    {
        $first = $this->service->create(42);
        $old = $this->service->exchange($first->pairingId, $first->code, 'same-device', 'challenge-1', ['import:write']);
        $second = $this->service->create(42);
        $new = $this->service->exchange($second->pairingId, $second->code, 'same-device', 'challenge-2', ['import:write']);

        try {
            $this->service->authenticate($old->credential, 'import:write');
            self::fail('The prior device credential must be revoked during re-pairing.');
        } catch (PairingException $exception) {
            self::assertSame('revoked_credential', $exception->errorCode);
        }
        self::assertNotSame($old->credential, $new->credential);
        self::assertSame(42, $this->service->authenticate($new->credential, 'import:write')->userId);
    }

    public function testAuthenticationRejectsCredentialsOutsideTheirScope(): void
    {
        $pairing = $this->service->create(42);
        $credential = $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('scope');
        $this->service->authenticate($credential->credential, 'asset:write');
    }

    public function testCodeReplayCannotIssueASecondCredential(): void
    {
        $pairing = $this->service->create(42);
        $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);

        $this->expectException(PairingException::class);
        $this->expectExceptionMessage('already used');
        $this->service->exchange($pairing->pairingId, $pairing->code, 'device', 'challenge', ['import:write']);
    }
}
