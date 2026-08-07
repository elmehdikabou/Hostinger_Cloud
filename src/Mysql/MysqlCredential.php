<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Un jeu d'identifiants MySQL, soit fourni dans la configuration, soit
 * decouvert dans le fichier de configuration d'un site.
 */
final readonly class MysqlCredential
{
    public function __construct(
        public string $user,
        public string $password,
        public string $host = 'localhost',
        public int $port = 3306,
        public ?string $database = null,
        /** D'ou vient cet acces : 'config' ou le chemin du fichier ou il a ete lu. */
        public string $source = 'config',
        /** Vrai si cet acces est cense voir toutes les bases du compte. */
        public bool $isAdmin = false,
    ) {
    }

    /**
     * Cle de dedoublonnage : inutile d'interroger deux fois le meme compte
     * parce que trois sites partagent les memes identifiants.
     */
    public function key(): string
    {
        return sha1(implode("\0", [$this->host, (string) $this->port, $this->user, $this->password]));
    }

    /** Libelle sans secret, affichable dans l'interface et les journaux. */
    public function label(): string
    {
        return "{$this->user}@{$this->host}" . ($this->isAdmin ? ' (acces global)' : '');
    }

    public function usable(): bool
    {
        return trim($this->user) !== '';
    }

    public function withDatabase(?string $database): self
    {
        return new self(
            $this->user,
            $this->password,
            $this->host,
            $this->port,
            $database,
            $this->source,
            $this->isAdmin,
        );
    }
}
