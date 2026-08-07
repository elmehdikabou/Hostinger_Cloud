<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

final readonly class FileContents
{
    public function __construct(
        /** Chemin relatif a la racine du site. */
        public string $path,
        public string $contents,
    ) {
    }
}
