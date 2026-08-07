<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Model\FindingKind;
use HostingerSpace\Storage\ScanRepository;

final class FindingsCommand implements Command
{
    public function name(): string
    {
        return 'findings';
    }

    public function description(): string
    {
        return 'Affiche les constats du dernier scan (--critical, --warning)';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $repository = $context->repository();
        $scan = $repository->latestScan();

        if ($scan === null) {
            $out->warn('Aucun scan enregistre. Lance : php bin/hspace scan');

            return 1;
        }

        $severity = match (true) {
            in_array('--critical', $arguments, true) => 'critical',
            in_array('--warning', $arguments, true) => 'warning',
            in_array('--info', $arguments, true) => 'info',
            default => null,
        };

        $scanId = (int) $scan['id'];
        $findings = $repository->findings($scanId, $severity);

        $out->title('Constats — scan #' . $scanId . ' du ' . Output::dateTime((int) $scan['finished_at']));

        if ($findings === []) {
            $out->success('Aucun constat a signaler.');
            $out->line();

            return 0;
        }

        foreach ($findings as $finding) {
            $kind = FindingKind::tryFrom((string) $finding['kind'])?->label() ?? $finding['kind'];

            $out->line();

            match ($finding['severity']) {
                'critical' => $out->error($finding['title']),
                'warning' => $out->warn($finding['title']),
                default => $out->info($finding['title']),
            };

            $out->dim('      [' . $kind . ']');
            $out->line('      ' . wordwrap((string) $finding['detail'], 92, "\n      "));

            foreach (ScanRepository::decode($finding['actions']) as $action) {
                $out->line('        → ' . $action);
            }
        }

        $out->line();

        return 0;
    }
}
