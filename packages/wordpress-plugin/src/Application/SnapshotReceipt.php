<?php

declare(strict_types=1);

namespace FEM\Application;

final class SnapshotReceipt
{
    public function __construct(public readonly string $snapshotId, public readonly string $designId, public readonly string $revision, public readonly string $contentHash)
    {
    }
}
