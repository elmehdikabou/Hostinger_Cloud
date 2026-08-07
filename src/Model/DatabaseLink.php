<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

/**
 * Le rattachement d'un site a une base, une fois confronte a la realite du
 * serveur MySQL.
 *
 * Le mot de passe lu dans le fichier de configuration ne figure pas ici :
 * il sert pendant le scan puis disparait. Cette classe est destinee a etre
 * stockee et affichee.
 */
final readonly class DatabaseLink
{
    public function __construct(
        public string $siteKey,
        public string $databaseName,
        public LinkState $state,
        public ?string $user = null,
        public string $host = 'localhost',
        public int $port = 3306,
        public ?string $tablePrefix = null,
        /** Chemin du fichier de configuration, relatif a la racine du site. */
        public string $sourceFile = '',
        public string $connectionName = 'default',
    ) {
    }
}
