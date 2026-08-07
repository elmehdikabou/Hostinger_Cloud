<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

use HostingerSpace\Mysql\MysqlCredential;

/**
 * Une base referencee par un site, telle que lue dans son fichier de config.
 *
 * Le mot de passe vit ici uniquement pendant le scan, pour pouvoir interroger
 * MySQL. Il n'est jamais ecrit dans la base d'inventaire ni affiche dans
 * l'interface : l'outil cartographie un hebergement, il n'a aucune raison de
 * devenir un second endroit ou les identifiants de tous les sites trainent.
 */
final readonly class DiscoveredDatabase
{
    public function __construct(
        public string $database,
        public ?string $user = null,
        public ?string $password = null,
        public string $host = 'localhost',
        public int $port = 3306,
        public ?string $tablePrefix = null,
        /** Chemin relatif du fichier d'ou vient l'information. */
        public string $sourceFile = '',
        /** Nom de la connexion pour les applications multi-bases. */
        public string $connectionName = 'default',
    ) {
    }

    public function named(): bool
    {
        return trim($this->database) !== '';
    }

    /**
     * Ces identifiants pointent-ils vers le MySQL de l'hebergement ?
     *
     * Un site branche sur une base externe (RDS, Scaleway, un autre serveur)
     * ne doit pas faire croire a une base manquante cote Hostinger.
     */
    public function isLocal(): bool
    {
        $host = strtolower(trim($this->host));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1', 'mysql', 'db'], true)
            || str_ends_with($host, '.hostinger.com')
            || str_ends_with($host, '.hostingersite.com');
    }

    public function toCredential(string $absoluteSource): ?MysqlCredential
    {
        if ($this->user === null || trim($this->user) === '' || !$this->isLocal()) {
            return null;
        }

        return new MysqlCredential(
            user: $this->user,
            password: $this->password ?? '',
            host: $this->host === '' ? 'localhost' : $this->host,
            port: $this->port,
            database: $this->named() ? $this->database : null,
            source: $absoluteSource,
        );
    }
}
