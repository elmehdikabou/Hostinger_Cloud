<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

/**
 * Une entree de dossier, normalisee entre le transport local et SSH.
 */
final readonly class DirEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDir,
        public bool $isLink,
        public int $size,
        public int $mtime,
    ) {
    }
}
