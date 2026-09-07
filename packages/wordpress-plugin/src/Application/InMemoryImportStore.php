<?php

declare(strict_types=1);

namespace FEM\Application;

final class InMemoryImportStore implements ImportStore
{
    /** @var array<string,ImportState> */
    private array $imports = [];

    public function save(ImportState $state): void
    {
        $this->imports[$state->id] = $state;
    }

    public function find(string $importId): ?ImportState
    {
        return $this->imports[$importId] ?? null;
    }

    public function markCommitted(string $importId): void
    {
        if (isset($this->imports[$importId])) {
            $this->imports[$importId]->missingAssets = [];
        }
    }

    public function delete(string $importId): void
    {
        unset($this->imports[$importId]);
    }
}
