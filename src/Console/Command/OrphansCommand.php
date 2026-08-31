<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

final class OrphansCommand implements Command
{
    public function name(): string
    {
        return 'orphans';
    }

    public function description(): string
    {
        return 'Liste les bases qu\'aucun site n\'utilise';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $repository = $context->repository();
        $scan = $repository->latestScan();

        if ($scan === null) {
            $out->warn('Aucun scan enregistre. Lance : php bin/hspace scan');

            return 1;
        }

        $scanId = (int) $scan['id'];
        $orphans = $repository->orphans($scanId);

        $out->title('Bases orphelines — scan #' . $scanId . ' du ' . Output::dateTime((int) $scan['finished_at']));

        if ($orphans === []) {
            if ((int) $scan['coverage_complete'] === 1) {
                $out->success('Aucune base orpheline : chaque base du serveur est utilisee par au moins un site.');
                $out->line();

                return 0;
            }

            /*
             * Sans acces MySQL global, les seules bases visibles sont celles
             * rattachees aux comptes lus dans les sites — donc des bases
             * utilisees. Zero n'est pas un resultat ici, c'est le seul
             * resultat possible : l'annoncer comme « aucune » serait faux.
             */
            $out->warn("Impossible a determiner — ce n'est pas qu'il n'y en a aucune.");
            $out->line();
            $out->line("  Un utilisateur MySQL ne voit que les bases auxquelles il est rattache.");
            $out->line("  Faute d'acces global, seuls les comptes lus dans tes sites ont ete");
            $out->line("  interroges, donc uniquement des bases deja utilisees. Une base");
            $out->line("  qu'aucun site n'utilise reste invisible — c'est celle qu'on cherche.");
            $out->line();
            $out->line('  Pour obtenir la vue complete :');
            $out->line();
            $out->line('   1. hPanel > Bases de donnees MySQL > cree un utilisateur, puis');
            $out->line('      rattache-le a TOUTES tes bases.');
            $out->line('   2. Renseigne « mysql.admin_user » et « mysql.admin_password » dans');
            $out->line('      ' . $context->config()->sourcePath);
            $out->line('   3. php bin/hspace scan');
            $out->line();
            $out->info('En attendant : php bin/hspace databases montre ce qui est visible.');
            $out->line();

            return 0;
        }

        /*
         * Deux populations de fiabilite opposee sous un seul tableau. Une base
         * que MySQL a ouverte et qu'aucun site ne declare est etablie : on peut
         * agir dessus. Un nom repris d'une liste collee n'est qu'une piste — la
         * base peut ne plus exister, ou servir a un site que le scan n'a pas su
         * lire. Les melanger noyait les quelques certitudes sous les doutes.
         */
        $rows = [];
        $pistes = [];
        $total = 0;
        $unmeasured = 0;

        foreach ($orphans as $database) {
            // Une base jamais ouverte n'a pas ete mesuree a zero : sa taille
            // est inconnue. L'afficher « 0 o » la designerait comme un reste
            // sans importance, alors qu'elle peut etre pleine.
            $measured = (int) ($database['measured'] ?? 1) === 1;

            if (!$measured) {
                $unmeasured++;
                $pistes[] = (string) $database['name'];

                continue;
            }

            $total += (int) $database['size_bytes'];

            $rows[] = [
                $database['name'],
                (string) $database['table_count'],
                Output::bytes((int) $database['size_bytes']),
                Output::since($database['updated_at'] === null ? null : (int) $database['updated_at']),
            ];
        }

        $out->line();
        $out->line('  Orphelines verifiees — ouvertes par MySQL, declarees par aucun site :');
        $out->line();

        if ($rows === []) {
            $out->dim('    Aucune. Les bases que l\'outil a pu ouvrir servent toutes a un site.');
        } else {
            $out->table(['Base', 'Tables', 'Taille', 'Derniere ecriture'], $rows, [1 => true, 2 => true]);
            $out->line();
            $out->info('Espace recuperable : ' . Output::bytes($total));
        }

        if ($pistes !== []) {
            $out->line();
            $out->line('  Pistes a confirmer — ' . count($pistes) . ' nom(s) repris de la liste collee,');
            $out->line('  jamais atteints par MySQL. Leur existence n\'est pas etablie :');
            $out->line();

            foreach (array_chunk($pistes, 2) as $paire) {
                $out->dim('    ' . implode('   ', array_map(
                    static fn (string $n): string => str_pad($n, 28),
                    $paire
                )));
            }
        }

        $out->line();

        if ((int) $scan['coverage_complete'] !== 1) {
            $out->warn("Inventaire partiel : cette liste peut contenir des faux positifs.");
            $out->dim('  ' . $scan['coverage_note']);
            $out->line();
        }

        $out->line('  Avant de supprimer quoi que ce soit :');
        $out->line();
        $out->line('   1. Sauvegarder :');
        $out->dim('        mysqldump -u UTILISATEUR -p NOM_BASE > ~/sauvegarde-NOM_BASE.sql');
        $out->line('   2. Verifier qu\'aucun script hors site n\'y fait reference :');
        $out->dim('        grep -rl NOM_BASE ~/domains ~/public_html 2>/dev/null');
        $out->line('   3. Supprimer depuis hPanel > Bases de donnees MySQL.');
        $out->line();

        return 0;
    }
}
