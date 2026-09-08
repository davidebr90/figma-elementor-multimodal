<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use DateTimeImmutable;
use FEM\Application\ImportService;
use FEM\Application\InMemoryImportStore;
use FEM\Api\RestController;
use FEM\Infrastructure\InMemoryAssetStore;
use FEM\Infrastructure\InMemoryAuditRepository;
use FEM\Infrastructure\InMemoryCredentialRepository;
use FEM\Infrastructure\InMemoryIdempotencyRepository;
use FEM\Infrastructure\InMemoryPairingRepository;
use FEM\Infrastructure\SchemaValidator;
use FEM\Infrastructure\SnapshotRepository;
use FEM\Security\FrozenClock;
use FEM\Security\PairingService;
use FEM\Security\SequenceRandomSource;
use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase
{
    public function testImportRouteAcceptsTheRawJsonBodyWithoutARequiredBodyParameter(): void
    {
        $route = RestController::importRouteDefinition();

        self::assertSame('POST', $route['methods']);
        self::assertArrayNotHasKey('args', $route);
    }

    public function testAssetOutsideTheStagedManifestReturnsAValidationResponse(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-08T10:00:00+00:00'));
        $pairings = new InMemoryPairingRepository();
        $credentials = new InMemoryCredentialRepository();
        $pairing = new PairingService($pairings, $credentials, new InMemoryAuditRepository(), $clock, new SequenceRandomSource('rest-controller'), 'unit-test-key');
        $code = $pairing->create(7);
        $credential = $pairing->exchange($code->pairingId, $code->code, 'figma-device', 'challenge', ['import:write', 'asset:write']);
        $imports = new ImportService(new SchemaValidator(), new InMemoryAssetStore(), new InMemoryImportStore(), new class implements \FEM\Infrastructure\DesignRepository {
            public function upsert(string $designId, string $sourceIdentity, string $schemaVersion, ?string $revision, DateTimeImmutable $now): void
            {
            }
        }, new class implements SnapshotRepository {
            public function append(string $snapshotId, string $designId, string $revision, string $schemaVersion, string $contentHash, array $document, DateTimeImmutable $now): void
            {
            }

            public function findByRevision(string $designId, string $revision): ?array
            {
                return null;
            }
        }, new InMemoryIdempotencyRepository(), $clock, new SequenceRandomSource('rest-import'));
        $state = $imports->stage(['assets' => []], $this->document(), $pairing->authenticate($credential->credential, 'import:write'));
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8VwAAAABJRU5ErkJggg==', true);
        self::assertIsString($bytes);
        $request = new RestControllerRequestStub(
            ['authorization' => 'Bearer ' . $credential->credential, 'content-type' => 'image/png'],
            ['importId' => $state->importId, 'sha256' => hash('sha256', $bytes)],
            $bytes,
        );

        $response = (new RestController($pairing, $imports))->asset($request);

        self::assertSame(422, $response->get_status());
        self::assertSame('invalid-asset', $response->get_data()['errors'][0]['code']);
    }

    /** @return array<string,mixed> */
    private function document(): array
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
        return $document;
    }
}

final class RestControllerRequestStub
{
    /** @param array<string,string> $headers @param array<string,string> $params */
    public function __construct(private readonly array $headers, private readonly array $params, private readonly string $body)
    {
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match WP_REST_Request's public interface.
    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match WP_REST_Request's public interface.
    public function get_param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match WP_REST_Request's public interface.
    public function get_body(): string
    {
        return $this->body;
    }
}
