<?php

declare(strict_types=1);

namespace HostingerSpace\Scanner;

use HostingerSpace\Transport\Shell;
use HostingerSpace\Transport\Transport;

/**
 * Mesure taille, nombre de fichiers et date du fichier le plus recent.
 *
 * Tout est calcule en une seule commande pour l'ensemble des sites. Un « du »
 * par site, ce serait cinquante allers-retours SSH la ou un seul suffit ; sur
 * une liaison a 80 ms, la difference se compte en minutes.
 *
 * La date du fichier le plus recent est le meilleur signal d'abandon dont on
 * dispose cote fichiers : un site vivant recoit des mises a jour, des medias,
 * des fichiers de cache.
 */
final class FilesystemStats
{
    /** @var array<int,string> */
    private array $excluded;

    private ?bool $supportsPrintf = null;

    /** @param array<int,string> $excluded Fragments de chemin a ignorer dans la mesure. */
    public function __construct(
        private readonly Transport $transport,
        array $excluded = [],
        private readonly int $timeoutSeconds = 120,
    ) {
        $this->excluded = $excluded;
    }

    /**
     * @param array<int,string> $paths Chemins absolus.
     *
     * @return array<string,array{size:int,files:int,mtime:?int}> Indexe par chemin.
     */
    public function measure(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $stats = $this->supportsPrintf() ? $this->measureWithFind($paths) : $this->measureWithDu($paths);

        // Un chemin qu'on n'a pas su mesurer doit rester present, a zero,
        // plutot que de disparaitre silencieusement de l'inventaire.
        foreach ($paths as $path) {
            $stats[$path] ??= ['size' => 0, 'files' => 0, 'mtime' => null];
        }

        return $stats;
    }

    /**
     * @param array<int,string> $paths
     *
     * @return array<string,array{size:int,files:int,mtime:?int}>
     */
    private function measureWithFind(array $paths): array
    {
        $pruneExpression = $this->prunePredicate();

        // Une passe de « find » par site, agregee par awk : date la plus
        // recente, nombre de fichiers et somme des tailles d'un seul coup.
        $script = sprintf(
            'for d in %s; do printf "%%s\t" "$d"; find "$d" %s -type f -printf "%%T@ %%s\n" 2>/dev/null | ' .
            'awk \'BEGIN{m=0;c=0;s=0}{c++;if($1>m)m=$1;s+=$2}END{printf "%%d\t%%d\t%%d\n",m,c,s}\'; done',
            implode(' ', array_map(Shell::quote(...), $paths)),
            $pruneExpression,
        );

        $result = $this->transport->exec($this->withTimeout($script));
        $stats = [];

        foreach ($result->lines() as $line) {
            $parts = explode("\t", $line);

            if (count($parts) < 4) {
                continue;
            }

            $mtime = (int) $parts[1];

            $stats[$parts[0]] = [
                'size' => (int) $parts[3],
                'files' => (int) $parts[2],
                'mtime' => $mtime > 0 ? $mtime : null,
            ];
        }

        return $stats;
    }

    /**
     * Repli sans « find -printf » (BusyBox, find non GNU) : on obtient la
     * taille et le nombre de fichiers, mais pas la date de derniere activite.
     *
     * @param array<int,string> $paths
     *
     * @return array<string,array{size:int,files:int,mtime:?int}>
     */
    private function measureWithDu(array $paths): array
    {
        $script = sprintf(
            'for d in %s; do s=$(du -sk "$d" 2>/dev/null | cut -f1); ' .
            'c=$(find "$d" -type f 2>/dev/null | wc -l); printf "%%s\t%%s\t%%s\n" "$d" "${s:-0}" "${c:-0}"; done',
            implode(' ', array_map(Shell::quote(...), $paths)),
        );

        $result = $this->transport->exec($this->withTimeout($script));
        $stats = [];

        foreach ($result->lines() as $line) {
            $parts = explode("\t", $line);

            if (count($parts) < 3) {
                continue;
            }

            $stats[$parts[0]] = [
                'size' => (int) $parts[1] * 1024,
                'files' => (int) $parts[2],
                'mtime' => null,
            ];
        }

        return $stats;
    }

    /**
     * Predicat « find » excluant les dossiers de cache et de dependances.
     *
     * Sans cela, un vendor/ ou un wp-content/cache/ regenere chaque nuit
     * ferait passer pour vivant un site qui ne bouge plus depuis des annees.
     */
    private function prunePredicate(): string
    {
        if ($this->excluded === []) {
            return '';
        }

        $clauses = [];

        foreach ($this->excluded as $fragment) {
            $fragment = trim($fragment, '/');

            if ($fragment !== '') {
                $clauses[] = '-path ' . Shell::quote('*/' . $fragment . '/*');
            }
        }

        return $clauses === [] ? '' : '-not \( ' . implode(' -o ', $clauses) . ' \)';
    }

    private function supportsPrintf(): bool
    {
        return $this->supportsPrintf ??= $this->transport
            ->exec('find . -maxdepth 0 -printf "" >/dev/null 2>&1 && echo oui')
            ->trimmed() === 'oui';
    }

    /**
     * Un site avec des centaines de milliers de fichiers ne doit pas bloquer
     * le scan complet : on borne, quitte a ne pas avoir sa taille.
     */
    private function withTimeout(string $script): string
    {
        return sprintf(
            'if command -v timeout >/dev/null 2>&1; then timeout %d sh -c %s; else sh -c %s; fi',
            $this->timeoutSeconds,
            Shell::quote($script),
            Shell::quote($script),
        );
    }
}
