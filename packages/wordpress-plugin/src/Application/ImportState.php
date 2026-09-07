<?php

declare(strict_types=1);

namespace FEM\Application;

use DateTimeImmutable;

final class ImportState
{
    /** @param array<string,mixed> $manifest @param array<string,mixed> $document @param list<string> $missingAssets */
    public function __construct(public readonly string $id, public readonly int $userId, public readonly string $deviceId, public readonly array $manifest, public readonly array $document, public array $missingAssets, public readonly DateTimeImmutable $createdAt, public readonly DateTimeImmutable $expiresAt)
    {
    }
}
