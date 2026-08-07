<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Trace de ce qu'un jeu d'identifiants a permis de voir. C'est ce qui permet
 * de dire honnetement, dans le rapport, si la liste des bases est exhaustive
 * ou seulement partielle.
 */
final readonly class CredentialProbe
{
    public function __construct(
        public string $label,
        public string $source,
        public bool $succeeded,
        public int $databasesSeen = 0,
        public ?string $error = null,
        public bool $grantsAllDatabases = false,
    ) {
    }
}
