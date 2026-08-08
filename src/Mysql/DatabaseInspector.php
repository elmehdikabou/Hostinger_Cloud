<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Dresse la liste des bases visibles, en combinant tous les acces MySQL dont
 * on dispose.
 *
 * Le point cle : sur un mutualise, chaque utilisateur MySQL ne voit que les
 * bases auxquelles il est rattache. Interroger un seul compte donnerait une
 * liste tronquee, et donc de fausses conclusions sur les orphelines. On
 * interroge donc tous les acces trouves et on fait l'union, tout en gardant la
 * trace de qui a vu quoi.
 */
final class DatabaseInspector
{
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    /** @var \Closure(MysqlCredential): MysqlGateway */
    private \Closure $gatewayFactory;

    /** @param callable(MysqlCredential): MysqlGateway $gatewayFactory */
    public function __construct(callable $gatewayFactory)
    {
        $this->gatewayFactory = $gatewayFactory(...);
    }

    /**
     * @param array<int,MysqlCredential> $credentials
     */
    public function inspect(array $credentials): DatabaseInventory
    {
        /** @var array<string,DatabaseInfo> $databases */
        $databases = [];
        $probes = [];
        $complete = false;
        $seenKeys = [];

        foreach ($credentials as $credential) {
            if (!$credential->usable()) {
                continue;
            }

            $key = $credential->key();

            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;

            $gateway = ($this->gatewayFactory)($credential);

            try {
                $grantsAll = $this->grantsAllDatabases($gateway);
                $found = $this->collect($gateway, $databases);

                $probes[] = new CredentialProbe(
                    label: $credential->label(),
                    source: $credential->source,
                    succeeded: true,
                    databasesSeen: $found,
                    grantsAllDatabases: $grantsAll,
                );

                $complete = $complete || $grantsAll;
            } catch (\Throwable $e) {
                $probes[] = new CredentialProbe(
                    label: $credential->label(),
                    source: $credential->source,
                    succeeded: false,
                    error: $this->shorten($e->getMessage()),
                );
            } finally {
                $gateway->close();
            }
        }

        ksort($databases, SORT_NATURAL | SORT_FLAG_CASE);

        return new DatabaseInventory($databases, $probes, $complete);
    }

    /**
     * @param array<string,DatabaseInfo> $databases Modifie sur place.
     */
    private function collect(MysqlGateway $gateway, array &$databases): int
    {
        $found = 0;

        /*
         * Une base reellement vide ne renvoie aucune ligne dans TABLES. Sans
         * distinguer « la requete a echoue » de « elle n'a rien renvoye », une
         * base vide passerait pour une base au contenu inconnu — et on
         * perdrait justement l'indice le plus utile pour la nettoyer.
         */
        $stats = $this->listTableStats($gateway);
        $measurable = $stats !== null;
        $stats ??= [];

        foreach ($this->listSchemas($gateway) as $name => $meta) {
            $found++;

            $info = $databases[$name] ??= new DatabaseInfo($name);
            $info->charset ??= $meta['charset'];
            $info->collation ??= $meta['collation'];
            $info->addSource($gateway->label());
            $info->measured = $info->measured || $measurable;
        }

        foreach ($stats as $name => $stat) {
            // Une base peut apparaitre ici sans avoir ete listee par SCHEMATA
            // si les privileges different entre les deux vues.
            $info = $databases[$name] ??= new DatabaseInfo($name);
            $info->addSource($gateway->label());
            $info->measured = true;

            // On garde la valeur la plus elevee : deux acces peuvent voir des
            // sous-ensembles differents des tables d'une meme base.
            $info->sizeBytes = max($info->sizeBytes, $stat['size_bytes']);
            $info->tableCount = max($info->tableCount, $stat['table_count']);
            $info->rowEstimate = max($info->rowEstimate, $stat['row_estimate']);

            if ($stat['updated_at'] !== null) {
                $info->updatedAt = max($info->updatedAt ?? 0, $stat['updated_at']);
            }

            if ($stat['created_at'] !== null) {
                $info->createdAt = min($info->createdAt ?? PHP_INT_MAX, $stat['created_at']);
            }
        }

        return $found;
    }

    /**
     * @return array<string,array{charset:?string,collation:?string}>
     */
    private function listSchemas(MysqlGateway $gateway): array
    {
        $schemas = [];

        try {
            $rows = $gateway->query(
                'SELECT SCHEMA_NAME AS name, ' .
                'DEFAULT_CHARACTER_SET_NAME AS charset, ' .
                'DEFAULT_COLLATION_NAME AS collation ' .
                'FROM information_schema.SCHEMATA'
            );

            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');

                if ($name === '' || $this->isSystemSchema($name)) {
                    continue;
                }

                $schemas[$name] = ['charset' => $row['charset'], 'collation' => $row['collation']];
            }

            return $schemas;
        } catch (MysqlException) {
            // Certains hebergeurs restreignent information_schema ; SHOW
            // DATABASES reste alors disponible, sans les jeux de caracteres.
        }

        foreach ($gateway->query('SHOW DATABASES') as $row) {
            $name = (string) (reset($row) ?: '');

            if ($name === '' || $this->isSystemSchema($name)) {
                continue;
            }

            $schemas[$name] = ['charset' => null, 'collation' => null];
        }

        return $schemas;
    }

    /**
     * @return array<string,array{table_count:int,size_bytes:int,row_estimate:int,updated_at:?int,created_at:?int}>|null
     *         null quand la requete elle-meme a echoue, a distinguer d'un
     *         resultat vide.
     */
    private function listTableStats(MysqlGateway $gateway): ?array
    {
        try {
            $rows = $gateway->query(
                'SELECT TABLE_SCHEMA AS name, ' .
                'COUNT(*) AS table_count, ' .
                'COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS size_bytes, ' .
                'COALESCE(SUM(TABLE_ROWS), 0) AS row_estimate, ' .
                'MAX(UPDATE_TIME) AS updated_at, ' .
                'MIN(CREATE_TIME) AS created_at ' .
                'FROM information_schema.TABLES ' .
                'GROUP BY TABLE_SCHEMA'
            );
        } catch (MysqlException) {
            return null;
        }

        $stats = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name === '' || $this->isSystemSchema($name)) {
                continue;
            }

            $stats[$name] = [
                'table_count' => (int) ($row['table_count'] ?? 0),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'row_estimate' => (int) ($row['row_estimate'] ?? 0),
                'updated_at' => $this->timestamp($row['updated_at'] ?? null),
                'created_at' => $this->timestamp($row['created_at'] ?? null),
            ];
        }

        return $stats;
    }

    /**
     * Un acces portant « ON *.* » voit tout le serveur : la liste des bases
     * est alors exhaustive, et une orpheline en est vraiment une.
     */
    private function grantsAllDatabases(MysqlGateway $gateway): bool
    {
        try {
            foreach ($gateway->query('SHOW GRANTS') as $row) {
                $grant = (string) (reset($row) ?: '');

                if (preg_match('/\bON\s+\*\.\*\s+TO\b/i', $grant) === 1) {
                    return true;
                }
            }
        } catch (MysqlException) {
            // SHOW GRANTS peut etre refuse ; on reste alors prudent.
        }

        return false;
    }

    private function isSystemSchema(string $name): bool
    {
        return in_array(strtolower($name), self::SYSTEM_SCHEMAS, true);
    }

    private function timestamp(?string $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 240 ? mb_substr($message, 0, 237) . '...' : $message;
    }
}
