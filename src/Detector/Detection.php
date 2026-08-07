<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

/**
 * Ce qu'un detecteur a compris d'un dossier de site.
 */
final readonly class Detection
{
    /**
     * @param array<int,DiscoveredDatabase> $databases
     * @param array<int,string>             $notes  Observations a remonter a l'utilisateur.
     */
    public function __construct(
        /** Identifiant technique : 'wordpress', 'laravel'... */
        public string $app,
        /** Libelle affichable : 'WordPress', 'Laravel'... */
        public string $label,
        public ?string $version = null,
        public array $databases = [],
        public array $notes = [],
        /** Fichier qui a permis l'identification. */
        public ?string $evidence = null,
    ) {
    }

    public function withDatabases(array $databases): self
    {
        return new self($this->app, $this->label, $this->version, $databases, $this->notes, $this->evidence);
    }

    public function withNotes(array $notes): self
    {
        return new self($this->app, $this->label, $this->version, $this->databases, $notes, $this->evidence);
    }

    public function describe(): string
    {
        return $this->version !== null ? "{$this->label} {$this->version}" : $this->label;
    }
}
