<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Storage\ScanComparer;

final class DiffCommand implements Command
{
    public function name(): string
    {
        return 'diff';
    }

    public function description(): string
    {
        return 'Compare deux scans : php bin/hspace diff <ancien> <recent>';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $repository = $context->repository();
        $scans = $repository->scans(2);

        // Sans argument, on compare l'avant-dernier au dernier : c'est la
        // question qu'on se pose neuf fois sur dix.
        $olderId = isset($arguments[0]) ? (int) $arguments[0] : (int) ($scans[1]['id'] ?? 0);
        $newerId = isset($arguments[1]) ? (int) $arguments[1] : (int) ($scans[0]['id'] ?? 0);

        if ($olderId === 0 || $newerId === 0) {
            $out->warn('Il faut au moins deux scans pour comparer. Lance : php bin/hspace scan');

            return 1;
        }

        foreach ([$olderId, $newerId] as $id) {
            if ($repository->scan($id) === null) {
                $out->error("Le scan #{$id} n'existe pas.");

                return 1;
            }
        }

        $diff = (new ScanComparer($context->database()))->compare($olderId, $newerId);

        $out->title("Changements entre le scan #{$olderId} et le scan #{$newerId}");

        if ($diff->isEmpty()) {
            $out->success('Rien n\'a change.');
            $out->line();

            return 0;
        }

        foreach ([
            'Sites apparus' => $diff->sitesAdded,
            'Sites disparus' => $diff->sitesRemoved,
            'Bases creees' => $diff->databasesAdded,
            'Bases supprimees' => $diff->databasesRemoved,
            'Nouvelles orphelines' => $diff->orphansAppeared,
            'Orphelines resorbees' => $diff->orphansResolved,
        ] as $label => $items) {
            if ($items === []) {
                continue;
            }

            $out->line();
            $out->line('  ' . $out->paint($label, '1'));

            foreach ($items as $item) {
                $out->line('    · ' . $item);
            }
        }

        if ($diff->findingsAppeared !== []) {
            $out->line();
            $out->line('  ' . $out->paint('Constats apparus', '1'));

            foreach ($diff->findingsAppeared as $finding) {
                match ($finding['severity']) {
                    'critical' => $out->error($finding['title']),
                    'warning' => $out->warn($finding['title']),
                    default => $out->info($finding['title']),
                };
            }
        }

        if ($diff->findingsResolved !== []) {
            $out->line();
            $out->line('  ' . $out->paint('Constats leves', '1'));

            foreach ($diff->findingsResolved as $finding) {
                $out->success($finding['title']);
            }
        }

        if ($diff->sizeChanges !== []) {
            $out->line();
            $out->line('  ' . $out->paint('Variations de taille notables', '1'));

            $rows = [];

            foreach (array_slice($diff->sizeChanges, 0, 15) as $change) {
                $delta = $change['after'] - $change['before'];

                $rows[] = [
                    $change['name'],
                    Output::bytes($change['before']),
                    Output::bytes($change['after']),
                    ($delta > 0 ? '+' : '−') . Output::bytes(abs($delta)),
                ];
            }

            $out->table(['Base', 'Avant', 'Apres', 'Variation'], $rows, [1 => true, 2 => true, 3 => true]);
        }

        $out->line();

        return 0;
    }
}
