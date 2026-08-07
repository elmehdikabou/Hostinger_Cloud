<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Connexion MySQL directe.
 *
 * Utilisee quand l'outil tourne sur l'hebergement lui-meme (mode « local »),
 * ou si tu as active l'acces MySQL distant dans hPanel. Plus rapide et plus
 * fiable que le client en ligne de commande quand elle est possible.
 */
final class PdoMysqlGateway implements MysqlGateway
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly MysqlCredential $credential,
        private readonly int $connectTimeout = 10,
    ) {
    }

    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $this->credential->host,
            $this->credential->port,
        );

        if ($this->credential->database !== null && $this->credential->database !== '') {
            $dsn .= ';dbname=' . $this->credential->database;
        }

        try {
            return $this->pdo = new \PDO($dsn, $this->credential->user, $this->credential->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => $this->connectTimeout,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            throw new MysqlException(
                "Connexion MySQL impossible ({$this->credential->label()}) : {$e->getMessage()}",
                previous: $e
            );
        }
    }

    public function query(string $sql): array
    {
        try {
            $statement = $this->pdo()->query($sql);

            if ($statement === false) {
                return [];
            }

            $rows = [];

            foreach ($statement->fetchAll() as $row) {
                $rows[] = array_map(
                    static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                    $row
                );
            }

            return $rows;
        } catch (\PDOException $e) {
            throw new MysqlException($e->getMessage(), previous: $e);
        }
    }

    public function probe(): ?string
    {
        try {
            $this->query('SELECT 1');

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function label(): string
    {
        return $this->credential->label();
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}
