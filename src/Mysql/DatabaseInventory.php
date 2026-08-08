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
        /**
         * Vrai quand la liste des noms vient d'une declaration manuelle
         * (hPanel) plutot que du serveur. Elle est alors exhaustive, meme si
         * les tailles restent inconnues : c'est le rattachement qui decide
         * d'une orpheline, pas le poids.
         */
        public bool $declared = false,
    ) {
    }

    /** Nombre de bases dont le contenu n'a pas pu etre mesure. */
    public function unmeasuredCount(): int
    {
        return count(array_filter($this->databases, static fn (DatabaseInfo $d): bool => $d->unmeasured()));
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
        // La liste declaree prime : elle rend la question decidable meme
        // quand aucun acces MySQL n'a abouti.
        if ($this->probes === [] && !$this->declared) {
            return "Aucun acces MySQL n'a pu etre etabli : la liste des bases est vide, et aucune conclusion sur les orphelines n'est possible.";
        }

        if ($this->declared) {
            $unmeasured = $this->unmeasuredCount();

            return "Liste complete : les " . count($this->databases) . " bases proviennent de la liste declaree depuis hPanel, "
                . "le rattachement aux sites est donc fiable."
                . ($unmeasured > 0
                    ? " En revanche, {$unmeasured} d'entre elles n'ont pas pu etre ouvertes : leur taille et leur date de "
                        . "derniere ecriture restent inconnues."
                    : '');
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
