<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

/**
 * Repli pour les sites PHP ecrits a la main.
 *
 * C'est souvent la categorie la plus interessante d'un vieil hebergement :
 * les projets sans framework sont ceux qu'on oublie, et donc ceux dont les
 * bases finissent orphelines. Faute de convention, on cherche les noms de
 * cles usuels dans les fichiers de configuration habituels, puis les appels
 * de connexion directs.
 */
final class GenericPhp implements Detector
{
    /** Emplacements ou un developpeur range sa configuration de base. */
    private const CANDIDATE_FILES = [
        'config.php', 'configuration.php', 'db.php', 'database.php', 'connect.php',
        'connexion.php', 'conn.php', 'settings.php', 'init.php', 'bdd.php',
        'inc/config.php', 'includes/config.php', 'include/config.php',
        'config/config.php', 'config/database.php', 'app/config.php',
        'admin/config.php', 'lib/config.php', 'core/config.php', 'src/config.php',
    ];

    private const NAME_KEYS = ['DB_NAME', 'DB_DATABASE', 'DATABASE', 'DBNAME', 'MYSQL_DATABASE', 'DATABASE_NAME', 'dbname', 'database', 'db_name', 'base'];
    private const USER_KEYS = ['DB_USER', 'DB_USERNAME', 'DBUSER', 'MYSQL_USER', 'DATABASE_USER', 'username', 'user', 'db_user', 'utilisateur'];
    private const PASSWORD_KEYS = ['DB_PASSWORD', 'DB_PASS', 'DB_PASSWD', 'DBPASS', 'MYSQL_PASSWORD', 'DATABASE_PASSWORD', 'password', 'pass', 'db_password', 'motdepasse'];
    private const HOST_KEYS = ['DB_HOST', 'DB_SERVER', 'DBHOST', 'MYSQL_HOST', 'DATABASE_HOST', 'hostname', 'host', 'servername', 'db_host', 'serveur'];

    public function priority(): int
    {
        return 900;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if (!$this->hasPhp($context)) {
            return null;
        }

        foreach (self::CANDIDATE_FILES as $candidate) {
            $source = $context->read($candidate, 262_144);

            if ($source === null) {
                continue;
            }

            $database = $this->fromNamedKeys($source, $candidate) ?? $this->fromConnectCall($source, $candidate);

            if ($database !== null) {
                return new Detection(
                    app: 'php',
                    label: 'PHP (sur mesure)',
                    databases: [$database],
                    notes: ["Base deduite de « {$candidate} » par convention de nommage : a verifier."],
                    evidence: $candidate,
                );
            }
        }

        return new Detection(
            app: 'php',
            label: 'PHP (sur mesure)',
            notes: ["Aucune configuration de base reconnue : si ce site utilise MySQL, sa base n'a pas pu etre rattachee."],
            evidence: 'index.php',
        );
    }

    private function hasPhp(SiteContext $context): bool
    {
        foreach ($context->listing() as $entry) {
            if (!$entry->isDir && str_ends_with(strtolower($entry->name), '.php')) {
                return true;
            }
        }

        return false;
    }

    /** Cherche les cles usuelles parmi les constantes, variables et tableaux. */
    private function fromNamedKeys(string $source, string $file): ?DiscoveredDatabase
    {
        $pools = [
            Parse::phpDefines($source),
            Parse::phpVariables($source),
            Parse::phpArrayEntries($source),
        ];

        $name = $this->firstMatch($pools, self::NAME_KEYS);

        if ($name === null || trim($name) === '') {
            return null;
        }

        [$host, $port] = Parse::hostAndPort($this->firstMatch($pools, self::HOST_KEYS) ?? 'localhost');

        return new DiscoveredDatabase(
            database: $name,
            user: $this->firstMatch($pools, self::USER_KEYS),
            password: $this->firstMatch($pools, self::PASSWORD_KEYS),
            host: $host,
            port: $port,
            sourceFile: $file,
        );
    }

    /**
     * Connexions ecrites en dur :
     *   mysqli_connect('localhost', 'u1_user', 'secret', 'u1_base')
     *   new PDO('mysql:host=localhost;dbname=u1_base', 'u1_user', 'secret')
     */
    private function fromConnectCall(string $source, string $file): ?DiscoveredDatabase
    {
        foreach (Parse::phpCallArguments($source, 'mysqli_connect') as $arguments) {
            if (($arguments[3] ?? null) !== null && trim($arguments[3]) !== '') {
                [$host, $port] = Parse::hostAndPort($arguments[0] ?? 'localhost');

                return new DiscoveredDatabase(
                    database: $arguments[3],
                    user: $arguments[1] ?? null,
                    password: $arguments[2] ?? null,
                    host: $host,
                    port: $port,
                    sourceFile: $file,
                );
            }
        }

        foreach (Parse::phpCallArguments($source, 'PDO') as $arguments) {
            $dsn = $arguments[0] ?? null;

            if ($dsn === null || !str_starts_with(strtolower($dsn), 'mysql')) {
                continue;
            }

            $parts = Parse::pdoDsn($dsn);
            $name = $parts['dbname'] ?? '';

            if (trim($name) === '') {
                continue;
            }

            [$host, $port] = Parse::hostAndPort($parts['host'] ?? 'localhost');

            return new DiscoveredDatabase(
                database: $name,
                user: $arguments[1] ?? null,
                password: $arguments[2] ?? null,
                host: $host,
                port: (int) ($parts['port'] ?? 0) ?: $port,
                sourceFile: $file,
            );
        }

        return null;
    }

    /**
     * @param array<int,array<string,string>> $pools
     * @param array<int,string>               $keys
     */
    private function firstMatch(array $pools, array $keys): ?string
    {
        foreach ($keys as $key) {
            foreach ($pools as $pool) {
                foreach ($pool as $candidate => $value) {
                    if (strcasecmp($candidate, $key) === 0 && trim($value) !== '') {
                        return $value;
                    }
                }
            }
        }

        /*
         * A defaut d'un nom exact, un nom prefixe : « $dolibarr_main_db_name »,
         * « $cfg_db_name », « $app_database ». Les applications maison en sont
         * pleines, et chacune inventerait sinon une orpheline.
         *
         * Se tromper dans ce sens est sans danger : une base rattachee a tort
         * n'est simplement pas proposee au nettoyage. Se tromper dans l'autre
         * fait declarer inutilisee une base en service — la seule erreur de cet
         * outil qui detruise des donnees. On penche donc du cote prudent.
         */
        foreach ($keys as $key) {
            foreach ($pools as $pool) {
                foreach ($pool as $candidate => $value) {
                    if (str_ends_with(strtolower($candidate), '_' . strtolower($key)) && trim($value) !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }
}
