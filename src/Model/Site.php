<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

use HostingerSpace\Detector\DiscoveredDatabase;

/**
 * Un site (ou une application) trouve sur l'hebergement.
 */
final class Site
{
    /** @var array<int,DiscoveredDatabase> Rempli pendant le scan, jamais persiste tel quel. */
    public array $discovered = [];

    /** @var array<int,string> */
    public array $notes = [];

    public int $sizeBytes = 0;
    public int $fileCount = 0;
    public ?int $lastModifiedAt = null;

    public ?int $httpStatus = null;
    public ?string $httpNote = null;
    public ?string $finalUrl = null;

    public ?int $sslExpiresAt = null;
    public ?string $sslIssuer = null;
    public ?string $sslNote = null;

    public ?int $domainExpiresAt = null;
    public ?string $registrar = null;

    public function __construct(
        /** Identifiant stable du site : son chemin relatif au dossier personnel. */
        public readonly string $key,
        /** Chemin absolu de la racine servie. */
        public readonly string $path,
        /** Domaine ou sous-domaine devine a partir de l'arborescence. */
        public readonly string $domain,
        public readonly string $app,
        public readonly string $appLabel,
        public readonly ?string $version = null,
        public readonly ?string $evidence = null,
        /** Renseigne si ce site est une application imbriquee dans un autre. */
        public readonly ?string $parentKey = null,
        /** Sous-chemin sous le domaine, par exemple « /blog ». */
        public readonly string $mountPath = '/',
    ) {
    }

    public function isNested(): bool
    {
        return $this->parentKey !== null;
    }

    public function describe(): string
    {
        return $this->version !== null ? "{$this->appLabel} {$this->version}" : $this->appLabel;
    }

    public function displayName(): string
    {
        return $this->mountPath === '/' ? $this->domain : $this->domain . $this->mountPath;
    }

    /** Un site qui, par nature, n'a pas de base a rattacher. */
    public function expectsDatabase(): bool
    {
        return !in_array($this->app, ['static', 'placeholder', 'empty', 'unknown'], true);
    }

    public function addNote(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
