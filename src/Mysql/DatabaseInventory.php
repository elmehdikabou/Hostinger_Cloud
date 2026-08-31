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
        /**
         * Nombre de bases que MySQL a trouvees mais que la liste declaree ne
         * mentionne pas. C'est la preuve que cette liste n'est pas exhaustive :
         * si le collage avait ete complet, tout ce que MySQL voit y figurerait.
         */
        public int $declaredGaps = 0,
    ) {
    }

    /** Nombre de bases dont le contenu n'a pas pu etre mesure. */
    public function unmeasuredCount(): int
    {
        return count(array_filter($this->databases, static fn (DatabaseInfo $d): bool => $d->unmeasured()));
    }

    /**
     * Ajoute les bases declarees a la main, sans toucher a ce qui est mesure.
     *
     * Les deux sources se completent au lieu de se remplacer : les bases
     * ouvertes par MySQL gardent leur taille et leur date de derniere
     * ecriture, celles connues du seul hPanel arrivent avec leur nom pour
     * tout bagage. Ecraser les premieres ferait perdre les seules mesures
     * dont on dispose — et l'espace reellement occupe redeviendrait inconnu.
     *
     * @param array<int,string> $names
     */
    public function withDeclared(array $names): self
    {
        $databases = $this->databases;

        /*
         * Une base que MySQL voit mais que la liste ignore prouve que le
         * collage etait partiel — hPanel pagine, et on ne copie souvent que le
         * premier ecran. L'outil ne peut pas deviner ce qui manque, mais il
         * peut constater qu'il manque quelque chose, et le dire.
         */
        $declared = array_flip($names);
        $gaps = count(array_diff_key($databases, $declared));

        foreach ($names as $name) {
            $databases[$name] ??= new DatabaseInfo($name);
            $databases[$name]->addSource('liste hPanel');
        }

        ksort($databases, SORT_NATURAL | SORT_FLAG_CASE);

        // La liste vient de hPanel : le rattachement aux sites devient decidable,
        // meme si la plupart de ces bases n'ont pas pu etre ouvertes — c'est lui
        // qui fait une orpheline, pas le poids. Les manques releves plus haut
        // disent, eux, jusqu'ou cette reponse porte.
        return new self($databases, $this->probes, complete: true, declared: true, declaredGaps: $gaps);
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

            $note = $this->declaredGaps > 0
                ? "Liste incomplete : MySQL a trouve {$this->declaredGaps} base(s) que ta liste ne mentionne pas. "
                    . "Le collage depuis hPanel n'etait donc pas entier — d'autres bases peuvent manquer, et avec elles "
                    . "d'autres orphelines. Celles trouvees ici restent reelles : aucun site ne les declare."
                : "Liste complete : les " . count($this->databases) . " bases proviennent de la liste declaree depuis "
                    . "hPanel, le rattachement aux sites est donc fiable.";

            return $note
                . ($unmeasured > 0
                    ? " {$unmeasured} base(s) n'ont pas pu etre ouvertes : leur taille et leur date de "
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
