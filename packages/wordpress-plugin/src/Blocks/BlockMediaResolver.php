<?php

declare(strict_types=1);

namespace FEM\Blocks;

use FEM\Elementor\MediaLibrary;

/** Resolves a staged FEM checksum into a WordPress media attachment for block output. */
interface BlockMediaResolver
{
    /** @return array{id:int,url:string}|null */
    public function resolve(string $sha256): ?array;
}

final class WpBlockMediaResolver implements BlockMediaResolver
{
    public function __construct(private readonly MediaLibrary $media = new MediaLibrary())
    {
    }

    public function resolve(string $sha256): ?array
    {
        return $this->media->attachmentFor($sha256);
    }
}
