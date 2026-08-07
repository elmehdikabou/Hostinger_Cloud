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
            $out->success('Aucune base orpheline : chaque base est utilisee par au moins un site.');
            $out->line();

            return 0;
        }

        $rows = [];
        $total = 0;

        foreach ($orphans as $database) {
            $total += (int) $database['size_bytes'];

            $rows[] = [
                $database['name'],
                (string) $database['table_count'],
                Output::bytes((int) $database['size_bytes']),
                Output::since($database['updated_at'] === null ? null : (int) $database['updated_at']),
            ];
        }

        $out->table(['Base', 'Tables', 'Taille', 'Derniere ecriture'], $rows, [1 => true, 2 => true]);

        $out->line();
        $out->info('Espace potentiellement recuperable : ' . Output::bytes($total));
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
