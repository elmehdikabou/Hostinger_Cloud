<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

final class HistoryCommand implements Command
{
    public function name(): string
    {
        return 'history';
    }

    public function description(): string
    {
        return 'Liste les scans enregistres';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $scans = $context->repository()->scans(30);

        $out->title('Historique des scans');

        if ($scans === []) {
            $out->warn('Aucun scan enregistre. Lance : php bin/hspace scan');

            return 1;
        }

        $rows = [];

        foreach ($scans as $scan) {
            $rows[] = [
                '#' . $scan['id'],
                Output::dateTime((int) $scan['finished_at']),
                (string) $scan['site_count'],
                (string) $scan['database_count'],
                (string) $scan['orphan_count'],
                (string) $scan['finding_count'],
                Output::bytes((int) $scan['database_bytes']),
                ((int) $scan['coverage_complete']) === 1 ? 'complet' : 'partiel',
            ];
        }

        $out->table(
            ['Scan', 'Date', 'Sites', 'Bases', 'Orphelines', 'Constats', 'Poids bases', 'Inventaire'],
            $rows,
            [2 => true, 3 => true, 4 => true, 5 => true, 6 => true]
        );

        $out->line();
        $out->dim('  Comparer deux scans : php bin/hspace diff <ancien> <recent>');
        $out->line();

        return 0;
    }
}
