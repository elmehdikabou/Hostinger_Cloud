<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

interface MysqlGateway
{
    /**
     * Execute une requete de lecture.
     *
     * @return list<array<string,?string>> Lignes en tableaux associatifs.
     *
     * @throws MysqlException
     */
    public function query(string $sql): array;

    /** Teste la connexion sans lever d'exception. Retourne null si tout va bien, le message d'erreur sinon. */
    public function probe(): ?string;

    public function label(): string;

    /** Libere les ressources (fichier d'options temporaire, connexion PDO...). */
    public function close(): void;
}
