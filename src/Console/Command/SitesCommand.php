<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Storage\ScanRepository;

final class SitesCommand implements Command
{
    public function name(): string
    {
        return 'sites';
    }

    public function description(): string
    {
        return 'Liste les sites du dernier scan, avec leurs bases';
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
        $links = [];

        foreach ($repository->links($scanId) as $link) {
            $links[$link['site_key']][] = $link['database_name'] .
                ($link['state'] === 'missing' ? ' (introuvable)' : '') .
                ($link['state'] === 'external' ? ' (externe)' : '');
        }

        $out->title('Sites — scan #' . $scanId . ' du ' . Output::dateTime((int) $scan['finished_at']));

        $rows = [];

        foreach ($repository->sites($scanId) as $site) {
            $name = $site['domain'] . ($site['mount_path'] !== '/' ? $site['mount_path'] : '');
            $application = $site['app_label'] . ($site['version'] !== null ? ' ' . $site['version'] : '');

            $rows[] = [
                $name,
                $application,
                implode(', ', $links[$site['site_key']] ?? []) ?: '—',
                Output::bytes((int) $site['size_bytes']),
                Output::since($site['last_modified_at'] === null ? null : (int) $site['last_modified_at']),
                $site['http_status'] === null ? '—' : (string) $site['http_status'],
            ];
        }

        $out->table(
            ['Site', 'Technologie', 'Base(s)', 'Taille', 'Activite', 'HTTP'],
            $rows,
            [3 => true]
        );

        $out->line();

        return 0;
    }
}
