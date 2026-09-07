<?php

declare(strict_types=1);

namespace FEM\Application;

use DateInterval;
use FEM\Infrastructure\AssetReceipt;
use FEM\Infrastructure\AssetStore;
use FEM\Infrastructure\DesignRepository;
use FEM\Infrastructure\IdempotencyRepository;
use FEM\Infrastructure\SchemaValidator;
use FEM\Infrastructure\SnapshotRepository;
use FEM\Security\AuthenticatedDevice;
use FEM\Security\Clock;
use FEM\Security\RandomSource;

final class ImportService
{
    public function __construct(private readonly SchemaValidator $validator, private readonly AssetStore $assets, private readonly ImportStore $imports, private readonly DesignRepository $designs, private readonly SnapshotRepository $snapshots, private readonly IdempotencyRepository $idempotency, private readonly Clock $clock, private readonly RandomSource $random)
    {
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $document */
    public function stage(array $manifest, array $document, AuthenticatedDevice $device): ImportReceipt
    {
        $this->validator->assertValid($document);
        $manifestAssets = $manifest['assets'] ?? [];
        if (!is_array($manifestAssets)) {
            throw new \InvalidArgumentException('Import manifest assets must be an object or list.');
        }
        $missing = [];
        $manifestHashes = [];
        foreach ($manifestAssets as $asset) {
            if (!is_array($asset) || !is_string($asset['sha256'] ?? null)) {
                throw new \InvalidArgumentException('Import manifest contains an invalid asset descriptor.');
            }
            $manifestHashes[] = $asset['sha256'];
            if (!$this->assets->has($asset['sha256'])) {
                $missing[] = $asset['sha256'];
            }
        }
        $documentHashes = [];
        foreach ((array) ($document['assets'] ?? []) as $asset) {
            if (is_array($asset) && is_string($asset['sha256'] ?? null)) {
                $documentHashes[] = $asset['sha256'];
            }
        }
        $manifestHashes = array_values(array_unique($manifestHashes));
        $documentHashes = array_values(array_unique($documentHashes));
        sort($manifestHashes, SORT_STRING);
        sort($documentHashes, SORT_STRING);
        if ($manifestHashes !== $documentHashes) {
            throw new \InvalidArgumentException('Import asset manifest does not exactly match the document assets.');
        }
        $importId = bin2hex($this->random->bytes(16));
        $now = $this->clock->now();
        $this->imports->save(new ImportState($importId, $device->userId, $device->deviceId, $manifest, $document, array_values(array_unique($missing)), $now, $now->add(new DateInterval('PT1H'))));
        return new ImportReceipt($importId, array_values(array_unique($missing)), $missing === [] ? 'commit' : 'upload-assets');
    }

    public function attachAsset(string $importId, AuthenticatedDevice $device, string $sha256, string $bytes, string $mime): AssetReceipt
    {
        $state = $this->requireImport($importId, $device);
        $expected = [];
        foreach (($state->manifest['assets'] ?? []) as $asset) {
            if (is_array($asset) && is_string($asset['sha256'] ?? null)) {
                $expected[] = $asset['sha256'];
            }
        }
        if (!in_array($sha256, $expected, true)) {
            throw new \InvalidArgumentException('Asset is not part of the staged manifest.');
        }
        $receipt = $this->assets->put($sha256, $bytes, $mime);
        $state->missingAssets = array_values(array_diff($state->missingAssets, [$receipt->sha256]));
        // The WordPress store rehydrates a fresh object per request, so the
        // shortened list must be written back or the commit still sees it missing.
        $this->imports->save($state);
        return $receipt;
    }

    /** @return array<string,mixed> */
    public function status(string $importId, AuthenticatedDevice $device): array
    {
        $state = $this->requireImport($importId, $device);
        return [
            'importId' => $state->id,
            'missingAssets' => $state->missingAssets,
            'capabilities' => $state->document['capabilities'] ?? [],
            'warnings' => $state->document['warnings'] ?? [],
            'syncState' => $state->missingAssets === [] ? 'ready' : 'awaiting-assets',
        ];
    }

    public function commit(string $importId, AuthenticatedDevice $device, string $baseRevision, string $idempotencyKey): SnapshotReceipt
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $requestHash = hash('sha256', $importId . "\n" . $baseRevision);
        $now = $this->clock->now();
        $principal = $this->principal($device);
        $route = '/imports/' . $importId . '/commit';
        $prior = $this->idempotency->find($principal, $route, 'POST', $idempotencyKey, $now);
        if ($prior !== null) {
            return $this->idempotentReceipt($prior, $requestHash);
        }
        $state = $this->requireImport($importId, $device);
        if ($state->missingAssets !== []) {
            throw new \RuntimeException('Import is missing mandatory assets.');
        }
        $sourceIdentity = (string) ($state->document['source']['identity'] ?? '');
        $revision = (string) ($state->document['revisions']['figmaRevision'] ?? '');
        if ($sourceIdentity === '' || $revision === '') {
            throw new \InvalidArgumentException('Document source identity and revision are required.');
        }
        if ($baseRevision !== '' && $baseRevision !== $revision) {
            throw new \RuntimeException('Base revision does not match the staged document.');
        }
        $designId = $this->designId($sourceIdentity);
        $contentHash = (string) ($state->document['integrity']['contentHash'] ?? '');
        $existing = $this->snapshots->findByRevision($designId, $revision);
        if ($existing !== null && !hash_equals($existing['contentHash'], $contentHash)) {
            throw new \RuntimeException('A different document already exists for this Figma revision.');
        }
        $snapshotId = $existing['snapshotId'] ?? bin2hex($this->random->bytes(16));
        if ($existing === null) {
            $this->snapshots->append($snapshotId, $designId, $revision, '1.0.0', $contentHash, $state->document, $now);
        }
        $this->designs->upsert($designId, $sourceIdentity, '1.0.0', $revision, $now);
        $result = new SnapshotReceipt($snapshotId, $designId, $revision, $contentHash);
        $response = ['snapshotId' => $snapshotId, 'designId' => $designId, 'revision' => $revision, 'contentHash' => $contentHash];
        if (!$this->idempotency->store($principal, $route, 'POST', $idempotencyKey, $requestHash, $response, $now->add(new DateInterval('P1D')), $now)) {
            $concurrent = $this->idempotency->find($principal, $route, 'POST', $idempotencyKey, $now);
            if ($concurrent !== null) {
                return $this->idempotentReceipt($concurrent, $requestHash);
            }
            throw new \RuntimeException('Could not claim idempotency key.');
        }
        $this->imports->markCommitted($importId);
        return $result;
    }

    private function requireImport(string $importId, AuthenticatedDevice $device): ImportState
    {
        $state = $this->imports->find($importId);
        if ($state !== null && $state->expiresAt <= $this->clock->now()) {
            $this->imports->delete($importId);
            $state = null;
        }
        if ($state === null || $state->userId !== $device->userId || !hash_equals($state->deviceId, $device->deviceId)) {
            throw new \OutOfRangeException('Import was not found.');
        }
        return $state;
    }

    private function designId(string $sourceIdentity): string
    {
        $hash = hash('sha256', $sourceIdentity);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-5' . substr($hash, 13, 3) . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }

    /** @param array<string,mixed> $prior */
    private function idempotentReceipt(array $prior, string $requestHash): SnapshotReceipt
    {
        if (!hash_equals((string) ($prior['requestHash'] ?? ''), $requestHash) || !is_array($prior['response'] ?? null)) {
            throw new \RuntimeException('Idempotency key was reused for a different request.');
        }
        $data = $prior['response'];
        return new SnapshotReceipt((string) ($data['snapshotId'] ?? ''), (string) ($data['designId'] ?? ''), (string) ($data['revision'] ?? ''), (string) ($data['contentHash'] ?? ''));
    }

    private function principal(AuthenticatedDevice $device): string
    {
        return hash('sha256', $device->userId . "\n" . $device->deviceId);
    }
}
