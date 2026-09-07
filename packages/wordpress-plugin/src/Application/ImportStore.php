<?php

declare(strict_types=1);

namespace FEM\Application;

interface ImportStore
{
    public function save(ImportState $state): void;

    public function find(string $importId): ?ImportState;

    public function markCommitted(string $importId): void;

    public function delete(string $importId): void;
}
