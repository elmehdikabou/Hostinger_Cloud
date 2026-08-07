<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Une base de donnees telle que vue par le serveur MySQL.
 */
final class DatabaseInfo
{
    /** @var array<int,string> Acces par lesquels cette base a ete vue. */
    public array $discoveredVia = [];

    public function __construct(
        public readonly string $name,
        public int $sizeBytes = 0,
        public int $tableCount = 0,
        public int $rowEstimate = 0,
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
        public ?string $charset = null,
        public ?string $collation = null,
    ) {
    }

    /**
     * Une base sans aucune table est un indice fort : creation de test,
     * migration abandonnee, ou reste d'un site supprime.
     */
    public function isEmpty(): bool
    {
        return $this->tableCount === 0;
    }

    public function addSource(string $label): void
    {
        if (!in_array($label, $this->discoveredVia, true)) {
            $this->discoveredVia[] = $label;
        }
    }
}
