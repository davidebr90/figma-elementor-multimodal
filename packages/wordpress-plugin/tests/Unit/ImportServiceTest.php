<?php

declare(strict_types=1);

namespace FEM\Tests\Unit;

use DateTimeImmutable;
use DateInterval;
use FEM\Application\ImportService;
use FEM\Infrastructure\DesignRepository;
use FEM\Infrastructure\DocumentIntegrity;
use FEM\Infrastructure\InMemoryAssetStore;
use FEM\Infrastructure\InMemoryIdempotencyRepository;
use FEM\Infrastructure\IdempotencyRepository;
use FEM\Application\InMemoryImportStore;
use FEM\Infrastructure\SchemaValidator;
use FEM\Infrastructure\SnapshotRepository;
use FEM\Security\AuthenticatedDevice;
use FEM\Security\FrozenClock;
use FEM\Security\SequenceRandomSource;
use PHPUnit\Framework\TestCase;

final class ImportServiceTest extends TestCase
{
    public function testStageReportsMissingManifestAssetsWithoutCommitting(): void
    {
        $service = $this->service();
        $sha256 = str_repeat('a', 64);
        $document = $this->documentWithAsset($sha256);

        $receipt = $service->stage(['assets' => [['sha256' => $sha256]]], $document, new AuthenticatedDevice(7, 'figma-device', ['import:write'], 'pairing'));

        self::assertSame([$sha256], $receipt->missingAssets);
        self::assertSame('upload-assets', $receipt->next);
    }

    public function testCommitRejectsPartiallyStagedImport(): void
    {
        $service = $this->service();
        $sha256 = str_repeat('a', 64);
        $receipt = $service->stage(['assets' => [['sha256' => $sha256]]], $this->documentWithAsset($sha256), new AuthenticatedDevice(7, 'figma-device', ['import:write'], 'pairing'));

        $this->expectExceptionMessage('missing mandatory assets');
        $service->commit($receipt->importId, $this->device(), '1', 'commit-key');
    }

    public function testForeignDeviceCannotReadStagedImport(): void
    {
        $service = $this->service();
        $receipt = $service->stage(['assets' => []], $this->document(), $this->device());

        $this->expectException(\OutOfRangeException::class);
        $service->status($receipt->importId, new AuthenticatedDevice(8, 'other-device', ['import:write'], 'other-pairing'));
    }

    public function testSameIdempotencyKeyMayBeUsedByDifferentDevicesWithoutCrossingResults(): void
    {
        $service = $this->service();
        $first = $service->stage(['assets' => []], $this->document(), $this->device());
        $otherDevice = new AuthenticatedDevice(8, 'other-device', ['import:write', 'asset:write'], 'other-pairing');
        $second = $service->stage(['assets' => []], $this->document(), $otherDevice);

        $firstSnapshot = $service->commit($first->importId, $this->device(), '1', 'shared-client-key');
        $secondSnapshot = $service->commit($second->importId, $otherDevice, '1', 'shared-client-key');

        self::assertSame($firstSnapshot->snapshotId, $secondSnapshot->snapshotId);
        self::assertSame($firstSnapshot->contentHash, $secondSnapshot->contentHash);
    }

    public function testExpiredStagingImportCannotBeReadOrCommitted(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-04T10:00:00+00:00'));
        $service = $this->service($clock);
        $receipt = $service->stage(['assets' => []], $this->document(), $this->device());
        $clock->advance(new DateInterval('PT61M'));

        $this->expectException(\OutOfRangeException::class);
        $service->status($receipt->importId, $this->device());
    }

    public function testManifestMustExactlyListDocumentAssets(): void
    {
        $document = $this->document();
        $document['assets'] = ['hero' => ['assetId' => 'hero', 'kind' => 'image', 'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'byteLength' => 10]];
        $document['integrity']['contentHash'] = DocumentIntegrity::contentHash($document);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset manifest');
        $this->service()->stage(['assets' => []], $document, $this->device());
    }

    private function device(): AuthenticatedDevice
    {
        return new AuthenticatedDevice(7, 'figma-device', ['import:write', 'asset:write'], 'pairing');
    }

    private function service(?FrozenClock $clock = null): ImportService
    {
        return new ImportService(new SchemaValidator(), new InMemoryAssetStore(), new InMemoryImportStore(), new class implements DesignRepository {
            public function upsert(string $designId, string $sourceIdentity, string $schemaVersion, ?string $revision, DateTimeImmutable $now): void {}
        }, new ImportServiceSnapshotRepository(), new InMemoryIdempotencyRepository(), $clock ?? new FrozenClock(new DateTimeImmutable('2026-09-04T10:00:00+00:00')), new SequenceRandomSource('import-tests'));
    }

    /** @return array<string,mixed> */
    private function document(): array
    {
        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
        return $document;
    }

    /** @return array<string,mixed> */
    private function documentWithAsset(string $sha256): array
    {
        $document = $this->document();
        $document['assets'] = ['hero' => ['assetId' => 'hero', 'kind' => 'image', 'sha256' => $sha256, 'mime' => 'image/png', 'byteLength' => 10]];
        $document['integrity']['contentHash'] = DocumentIntegrity::contentHash($document);

        return $document;
    }
}

final class ImportServiceSnapshotRepository implements SnapshotRepository
{
    /** @var array<string,array{snapshotId:string,contentHash:string}> */
    private array $snapshots = [];

    public function append(string $snapshotId, string $designId, string $revision, string $schemaVersion, string $contentHash, array $document, DateTimeImmutable $now): void
    {
        $this->snapshots[$designId . ':' . $revision] = ['snapshotId' => $snapshotId, 'contentHash' => $contentHash];
    }

    public function findByRevision(string $designId, string $revision): ?array
    {
        return $this->snapshots[$designId . ':' . $revision] ?? null;
    }
}
