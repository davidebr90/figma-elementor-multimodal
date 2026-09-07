<?php

declare(strict_types=1);

namespace FEM\Application;

final class ImportReceipt
{
    /** @param list<string> $missingAssets */
    public function __construct(public readonly string $importId, public readonly array $missingAssets, public readonly string $next)
    {
    }
}
