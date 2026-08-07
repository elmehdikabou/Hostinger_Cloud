<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Resultat de l'inspection MySQL : les bases vues, et surtout le degre de
 * confiance qu'on peut accorder a cette liste.
 */
final readonly class DatabaseInventory
{
    /**
     * @param array<string,DatabaseInfo> $databases  Indexe par nom de base.
     * @param array<int,CredentialProbe> $probes
     */
    public function __construct(
        public array $databases,
        public array $probes,
        public bool $complete,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], false);
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->databases);
    }

    public function get(string $name): ?DatabaseInfo
    {
        return $this->databases[$name] ?? null;
    }

    public function totalSize(): int
    {
        return array_sum(array_map(static fn (DatabaseInfo $d): int => $d->sizeBytes, $this->databases));
    }

    /** @return array<int,CredentialProbe> */
    public function failures(): array
    {
        return array_values(array_filter($this->probes, static fn (CredentialProbe $p): bool => !$p->succeeded));
    }

    /**
     * Phrase a afficher en tete du rapport : sans acces global, une base
     * « orpheline » peut en realite etre invisible plutot qu'inutilisee, et
     * l'utilisateur doit le savoir avant de supprimer quoi que ce soit.
     */
    public function coverageNote(): string
    {
        if ($this->probes === []) {
            return "Aucun acces MySQL n'a pu etre etabli : la liste des bases est vide, et aucune conclusion sur les orphelines n'est possible.";
        }

        if ($this->complete) {
            return "Couverture complete : un acces MySQL voyant toutes les bases du compte a ete utilise. La liste des orphelines est fiable.";
        }

        $working = count($this->probes) - count($this->failures());

        return "Couverture partielle : la liste vient de {$working} acces MySQL rattaches chacun a leurs propres bases. " .
            "Une base existante mais visible par aucun de ces acces n'apparait pas ici, et une base listee comme orpheline " .
            "reste a verifier. Pour une vue exhaustive, cree dans hPanel un utilisateur MySQL rattache a toutes tes bases " .
            "et renseigne-le dans « mysql.admin_user ».";
    }
}
