<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Liste de bases declaree a la main, copiee depuis hPanel.
 *
 * Sur un mutualise, chaque base a son propre utilisateur MySQL et personne ne
 * les voit toutes : l'inventaire par MySQL y est donc structurellement
 * incomplet. Mais repondre « quelles bases ne servent a personne » ne demande
 * pas d'ouvrir les bases — il suffit de connaitre leurs noms et de les
 * confronter a ce que les sites declarent.
 *
 * hPanel affiche cette liste. La coller ici rend la question decidable sans
 * aucun acces MySQL supplementaire. Les tailles restent inconnues, ce qui est
 * un moindre mal : c'est le rattachement qui compte.
 */
final class KnownDatabases
{
    /**
     * Extrait les noms de bases d'un texte colle depuis hPanel.
     *
     * Le collage est desordonne — deux colonnes, des « Acceder a phpMyAdmin »
     * intercales, des lignes vides. On ne cherche donc pas a lire une
     * structure : on releve les identifiants qui ressemblent a un nom de base,
     * et on ecarte la seconde colonne, qui est l'utilisateur.
     *
     * @return array<int,string> Noms uniques, tries.
     */
    public static function parse(string $text): array
    {
        /*
         * hPanel colle une base et son utilisateur sur deux lignes distinctes,
         * separees d'un bloc a l'autre par « Acceder a phpMyAdmin ». Sans
         * tenir compte de ce separateur, l'utilisateur serait pris pour une
         * base — et une base inexistante qu'aucun site ne declare ressortirait
         * en orpheline. On ne peut pas se permettre ce faux positif : c'est
         * celui qui fait supprimer la mauvaise chose.
         */
        $names = [];

        if (preg_match('/phpmyadmin/i', $text) === 1) {
            foreach (preg_split('/^.*phpmyadmin.*$/mi', $text) ?: [$text] as $block) {
                foreach (self::firstIdentifier($block) as $name) {
                    $names[$name] = true;
                }
            }
        } else {
            /*
             * Sans separateur, chaque ligne porte sa propre base : c'est le
             * cas d'une liste deja rangee, dont ce fichier lui-meme. Appliquer
             * ici la regle « une base par bloc » n'en relirait qu'une seule,
             * et l'inventaire se viderait en silence d'un import a l'autre.
             */
            foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
                foreach (self::firstIdentifier($line) as $name) {
                    $names[$name] = true;
                }
            }
        }

        $names = self::keepAccountPrefix(array_keys($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Ecarte ce qui ne partage pas le prefixe de compte dominant.
     *
     * Sur un mutualise, toutes les bases d'un compte portent le meme prefixe
     * (« u736304795_ »). Un mot de passage attrape dans le collage — un titre,
     * une legende — ne le porte pas, et deviendrait sinon une base que nul
     * site ne declare : une orpheline inventee de toutes pieces.
     *
     * Le filtre ne s'applique que si un prefixe domine vraiment. Sur un
     * serveur ou les bases n'ont aucun prefixe commun, tout est conserve.
     *
     * @param array<int,string> $names
     *
     * @return array<int,string>
     */
    private static function keepAccountPrefix(array $names): array
    {
        if (count($names) < 3) {
            return $names;
        }

        $counts = [];

        foreach ($names as $name) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9]*_)/', $name, $matches) === 1) {
                $counts[$matches[1]] = ($counts[$matches[1]] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return $names;
        }

        arsort($counts);
        $prefix = array_key_first($counts);

        // « Dominant » veut dire la majorite franche : en deca, le prefixe est
        // une coincidence et filtrer dessus supprimerait de vraies bases.
        if ($counts[$prefix] < count($names) * 0.6) {
            return $names;
        }

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, (string) $prefix)
        ));
    }

    /**
     * Le premier identifiant plausible d'un bloc, c'est-a-dire la base ; ce
     * qui suit est son utilisateur.
     *
     * Une ligne a deux colonnes porte deja la paire : on n'y prend alors que
     * la premiere, et le bloc est traite ligne par ligne.
     *
     * @return array<int,string>
     */
    private static function firstIdentifier(string $block): array
    {
        $found = [];
        $takenInBlock = false;

        foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $columns = array_values(array_filter(
                array_map(trim(...), preg_split('/[\t;,]+|\s{2,}/', $line) ?: []),
                static fn (string $value): bool => $value !== ''
            ));

            // Deux colonnes sur la meme ligne : la paire complete est la.
            if (count($columns) >= 2 && self::looksLikeDatabase($columns[0])) {
                $found[] = $columns[0];
                $takenInBlock = true;

                continue;
            }

            if (!$takenInBlock && self::looksLikeDatabase($columns[0] ?? '')) {
                $found[] = $columns[0];
                $takenInBlock = true;
            }
        }

        return $found;
    }

    private static function looksLikeDatabase(string $value): bool
    {
        if ($value === '' || strlen($value) > 64) {
            return false;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) !== 1) {
            return false;
        }

        // Les schemas du serveur ne sont pas des bases de l'utilisateur.
        // Les schemas du serveur et les en-tetes de tableau colles avec la
        // liste ne sont pas des bases de l'utilisateur.
        return !in_array(strtolower($value), [
            'information_schema', 'performance_schema', 'mysql', 'sys',
            'database', 'databases', 'base', 'bases', 'utilisateur', 'utilisateurs',
            'action', 'actions', 'user', 'users', 'name', 'nom', 'taille', 'size',
        ], true);
    }

    /** Lit la liste depuis un fichier, ou un tableau vide s'il est absent. */
    public static function fromFile(?string $path): array
    {
        if ($path === null || $path === '' || !is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        return $contents === false ? [] : self::parse($contents);
    }
}
