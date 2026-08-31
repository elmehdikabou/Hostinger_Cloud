<?php

declare(strict_types=1);

namespace HostingerSpace\Storage;

/**
 * Base SQLite locale de l'inventaire, et son schema.
 *
 * Aucun mot de passe n'y figure. Les identifiants lus dans les sites servent
 * pendant le scan puis sont oublies : un outil qui cartographie un
 * hebergement n'a pas a devenir un second endroit ou traine la totalite des
 * acces MySQL du compte.
 */
final class Database
{
    private const SCHEMA_VERSION = 3;

    private \PDO $pdo;

    public function __construct(public readonly string $path)
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Impossible de creer le dossier de stockage : {$directory}");
        }

        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $parameters */
    public function run(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        // Le type doit etre explicite : SQLite refuse une valeur liee comme
        // chaine derriere LIMIT ou OFFSET, et execute($parameters) lie tout
        // en chaine.
        foreach ($parameters as $name => $value) {
            $statement->bindValue(
                is_int($name) ? $name + 1 : $name,
                $value,
                match (true) {
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                }
            );
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string|int,mixed> $parameters
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $parameters = []): array
    {
        return $this->run($sql, $parameters)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $parameters
     *
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $parameters = []): ?array
    {
        $row = $this->run($sql, $parameters)->fetch();

        return $row === false ? null : $row;
    }

    public function transaction(callable $body): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $body($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    private function migrate(): void
    {
        $current = (int) ($this->pdo->query('PRAGMA user_version')?->fetchColumn() ?: 0);

        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        if ($current < 1) {
            $this->pdo->exec($this->initialSchema());
        }

        if ($current < 2) {
            /*
             * Distinguer « mesuree a zero » de « jamais ouverte ». Sans cette
             * colonne, une base connue par la seule liste hPanel s'affichait
             * « 0 table, 0 o » : la description exacte d'une coquille vide,
             * alors que son contenu n'a jamais ete regarde. C'est le genre de
             * ligne qui fait supprimer une base pleine.
             */
            $this->pdo->exec('ALTER TABLE databases ADD COLUMN measured INTEGER NOT NULL DEFAULT 1');
        }

        if ($current < 3) {
            /*
             * Le nombre de bases que MySQL a vues mais que la liste declaree
             * ignore. La note de couverture le dit deja, mais elle n'est
             * affichee que sur un inventaire partiel — or c'est justement un
             * inventaire annonce complet que ce constat vient contredire.
             */
            $this->pdo->exec('ALTER TABLE scans ADD COLUMN declared_gaps INTEGER NOT NULL DEFAULT 0');
        }

        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    private function initialSchema(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS scans (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at        INTEGER NOT NULL,
                finished_at       INTEGER NOT NULL,
                host              TEXT    NOT NULL,
                mode              TEXT    NOT NULL,
                coverage_complete INTEGER NOT NULL DEFAULT 0,
                coverage_note     TEXT,
                site_count        INTEGER NOT NULL DEFAULT 0,
                database_count    INTEGER NOT NULL DEFAULT 0,
                orphan_count      INTEGER NOT NULL DEFAULT 0,
                finding_count     INTEGER NOT NULL DEFAULT 0,
                disk_bytes        INTEGER NOT NULL DEFAULT 0,
                database_bytes    INTEGER NOT NULL DEFAULT 0,
                errors            TEXT    NOT NULL DEFAULT '[]'
            );

            CREATE TABLE IF NOT EXISTS sites (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id           INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                site_key          TEXT    NOT NULL,
                path              TEXT    NOT NULL,
                domain            TEXT    NOT NULL,
                mount_path        TEXT    NOT NULL DEFAULT '/',
                parent_key        TEXT,
                app               TEXT    NOT NULL,
                app_label         TEXT    NOT NULL,
                version           TEXT,
                evidence          TEXT,
                size_bytes        INTEGER NOT NULL DEFAULT 0,
                file_count        INTEGER NOT NULL DEFAULT 0,
                last_modified_at  INTEGER,
                http_status       INTEGER,
                http_note         TEXT,
                final_url         TEXT,
                ssl_expires_at    INTEGER,
                ssl_issuer        TEXT,
                ssl_note          TEXT,
                domain_expires_at INTEGER,
                registrar         TEXT,
                notes             TEXT    NOT NULL DEFAULT '[]'
            );

            CREATE TABLE IF NOT EXISTS databases (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id        INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                name           TEXT    NOT NULL,
                size_bytes     INTEGER NOT NULL DEFAULT 0,
                table_count    INTEGER NOT NULL DEFAULT 0,
                row_estimate   INTEGER NOT NULL DEFAULT 0,
                created_at     INTEGER,
                updated_at     INTEGER,
                charset        TEXT,
                collation      TEXT,
                discovered_via TEXT    NOT NULL DEFAULT '[]',
                is_orphan      INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS links (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id         INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                site_key        TEXT    NOT NULL,
                database_name   TEXT    NOT NULL,
                state           TEXT    NOT NULL,
                db_user         TEXT,
                db_host         TEXT,
                db_port         INTEGER,
                table_prefix    TEXT,
                source_file     TEXT,
                connection_name TEXT
            );

            CREATE TABLE IF NOT EXISTS findings (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id      INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                kind         TEXT    NOT NULL,
                severity     TEXT    NOT NULL,
                title        TEXT    NOT NULL,
                detail       TEXT    NOT NULL,
                subject_type TEXT    NOT NULL,
                subject      TEXT    NOT NULL,
                actions      TEXT    NOT NULL DEFAULT '[]'
            );

            CREATE INDEX IF NOT EXISTS idx_sites_scan     ON sites(scan_id);
            CREATE INDEX IF NOT EXISTS idx_sites_key      ON sites(site_key);
            CREATE INDEX IF NOT EXISTS idx_databases_scan ON databases(scan_id);
            CREATE INDEX IF NOT EXISTS idx_databases_name ON databases(name);
            CREATE INDEX IF NOT EXISTS idx_links_scan     ON links(scan_id);
            CREATE INDEX IF NOT EXISTS idx_links_db       ON links(scan_id, database_name);
            CREATE INDEX IF NOT EXISTS idx_links_site     ON links(scan_id, site_key);
            CREATE INDEX IF NOT EXISTS idx_findings_scan  ON findings(scan_id);
            SQL;
    }
}
