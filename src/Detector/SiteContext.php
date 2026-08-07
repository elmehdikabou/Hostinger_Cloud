<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

use HostingerSpace\Transport\DirEntry;
use HostingerSpace\Transport\Transport;

/**
 * Vue d'un dossier de site pour les detecteurs, avec cache.
 *
 * Le cache n'est pas un detail de confort : en SSH, chaque verification
 * d'existence est un aller-retour reseau. Une dizaine de detecteurs qui
 * testent chacun cinq fichiers sur cinquante sites, cela ferait des milliers
 * d'allers-retours. On resout donc les existences a partir du listing du
 * dossier parent, lu une seule fois.
 */
final class SiteContext
{
    /** @var array<string,?string> */
    private array $fileCache = [];

    /** @var array<string,array<string,DirEntry>> */
    private array $listingCache = [];

    public function __construct(
        public readonly Transport $transport,
        public readonly string $root,
        public readonly string $label,
    ) {
    }

    public function path(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return $relative === '' ? $this->root : rtrim($this->root, '/') . '/' . $relative;
    }

    /** Lecture d'un fichier du site, relative a sa racine. */
    public function read(string $relative, int $maxBytes = 524_288): ?string
    {
        if (array_key_exists($relative, $this->fileCache)) {
            return $this->fileCache[$relative];
        }

        if (!$this->exists($relative)) {
            return $this->fileCache[$relative] = null;
        }

        return $this->fileCache[$relative] = $this->transport->read($this->path($relative), $maxBytes);
    }

    /** Premier fichier existant parmi plusieurs candidats, avec son contenu. */
    public function readFirst(string ...$candidates): ?FileContents
    {
        foreach ($candidates as $candidate) {
            $contents = $this->read($candidate);

            if ($contents !== null && trim($contents) !== '') {
                return new FileContents($candidate, $contents);
            }
        }

        return null;
    }

    public function exists(string $relative): bool
    {
        return $this->entry($relative) !== null;
    }

    public function isDir(string $relative): bool
    {
        return $this->entry($relative)?->isDir ?? false;
    }

    public function isFile(string $relative): bool
    {
        $entry = $this->entry($relative);

        return $entry !== null && !$entry->isDir;
    }

    /** Tous les candidats existent-ils ? */
    public function hasAll(string ...$relatives): bool
    {
        foreach ($relatives as $relative) {
            if (!$this->exists($relative)) {
                return false;
            }
        }

        return true;
    }

    /** Au moins un des candidats existe-t-il ? */
    public function hasAny(string ...$relatives): bool
    {
        foreach ($relatives as $relative) {
            if ($this->exists($relative)) {
                return true;
            }
        }

        return false;
    }

    private function entry(string $relative): ?DirEntry
    {
        $relative = trim($relative, '/');

        if ($relative === '') {
            return null;
        }

        $slash = strrpos($relative, '/');
        $dir = $slash === false ? '' : substr($relative, 0, $slash);
        $name = $slash === false ? $relative : substr($relative, $slash + 1);

        return $this->listing($dir)[$name] ?? null;
    }

    /**
     * Listing d'un sous-dossier, indexe par nom et mis en cache.
     *
     * @return array<string,DirEntry>
     */
    public function listing(string $relativeDir = ''): array
    {
        $relativeDir = trim($relativeDir, '/');

        if (isset($this->listingCache[$relativeDir])) {
            return $this->listingCache[$relativeDir];
        }

        $entries = [];

        foreach ($this->transport->listDir($this->path($relativeDir)) as $entry) {
            $entries[$entry->name] = $entry;
        }

        return $this->listingCache[$relativeDir] = $entries;
    }
}
