<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Model\Severity;
use HostingerSpace\Scanner\ScanRunner;
use HostingerSpace\Storage\ScanComparer;

final class ScanCommand implements Command
{
    public function name(): string
    {
        return 'scan';
    }

    public function description(): string
    {
        return 'Lance un scan complet et l\'enregistre dans l\'historique';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Scan de l\'espace Hostinger');

        $problems = $context->config()->validate();

        foreach ($problems as $problem) {
            $out->error($problem);
        }

        if ($problems !== []) {
            $out->line();
            $out->info('Lance « php bin/hspace doctor » pour un diagnostic detaille.');

            return 1;
        }

        $runner = new ScanRunner(
            $context->config(),
            $context->transport(),
            function (string $step, string $message) use ($out): void {
                match ($step) {
                    'site', 'domaine' => $out->info($message),
                    default => $out->step($message),
                };
            }
        );

        $result = $runner->run();
        $repository = $context->repository();
        $previous = $repository->latestScan();
        $scanId = $repository->save($result);

        $out->title('Resultat');

        $out->pairs([
            'Scan' => "#{$scanId}",
            'Duree' => $result->duration() . ' s',
            'Sites' => (string) count($result->sites),
            // Annoncer « 45 (0 o) » quand aucune base n'a pu etre ouverte
            // ferait passer un espace inconnu pour un espace vide.
            'Bases de donnees' => count($result->inventory->databases) . ' ('
                . ($result->inventory->unmeasuredCount() === count($result->inventory->databases)
                    ? 'taille inconnue'
                    : Output::bytes($result->inventory->totalSize())) . ')',
            'Bases orphelines' => (string) count($result->analysis->orphans),
            'Espace disque' => Output::bytes($result->totalDiskUsage()),
        ]);

        $out->line();
        $out->dim('  ' . $result->inventory->coverageNote());

        foreach ($result->errors as $error) {
            $out->warn($error);
        }

        $this->showFindings($out, $result->analysis->findingsBySeverity());
        $this->showOrphans($out, $result);

        if ($previous !== null) {
            $this->showChanges($out, $context, (int) $previous['id'], $scanId);
        }

        $out->line();
        $out->line('  Vue detaillee : php bin/hspace serve');
        $out->line();

        return 0;
    }

    /** @param array<int,\HostingerSpace\Model\Finding> $findings */
    private function showFindings(Output $out, array $findings): void
    {
        $critical = array_filter($findings, static fn ($f): bool => $f->severity === Severity::Critical);
        $warnings = array_filter($findings, static fn ($f): bool => $f->severity === Severity::Warning);

        if ($findings === []) {
            $out->line();
            $out->success('Aucun constat : tout est en ordre.');

            return;
        }

        $out->title('Constats (' . count($critical) . ' critiques, ' . count($warnings) . ' a verifier)');

        foreach (array_slice($findings, 0, 12) as $finding) {
            match ($finding->severity) {
                Severity::Critical => $out->error($finding->title),
                Severity::Warning => $out->warn($finding->title),
                Severity::Info => $out->info($finding->title),
            };
        }

        if (count($findings) > 12) {
            $out->dim('    … et ' . (count($findings) - 12) . ' autre(s).');
        }
    }

    private function showOrphans(Output $out, \HostingerSpace\Scanner\ScanResult $result): void
    {
        if ($result->analysis->orphans === []) {
            return;
        }

        $out->title('Bases orphelines');

        $rows = [];

        foreach ($result->analysis->orphans as $name) {
            $info = $result->inventory->get($name);

            // « ? » et non « 0 o » : cette base n'a jamais pu etre ouverte,
            // donc rien ne dit qu'elle soit vide.
            $known = $info !== null && $info->measured;

            $rows[] = [
                $name,
                $info === null ? '—' : ($known ? (string) $info->tableCount : '?'),
                $info === null ? '—' : ($known ? Output::bytes($info->sizeBytes) : '?'),
                $info === null ? '—' : ($known ? Output::since($info->updatedAt) : '?'),
            ];
        }

        $out->table(['Base', 'Tables', 'Taille', 'Derniere ecriture'], $rows, [1 => false, 2 => true]);
        $out->line();
        $out->dim('  Sauvegarde avant toute suppression :');
        $out->dim('    mysqldump -u UTILISATEUR -p NOM_BASE > ~/sauvegarde-NOM_BASE.sql');
    }

    private function showChanges(Output $out, Context $context, int $previousId, int $currentId): void
    {
        $diff = (new ScanComparer($context->database()))->compare($previousId, $currentId);

        if ($diff->isEmpty()) {
            $out->line();
            $out->info("Aucun changement depuis le scan #{$previousId}.");

            return;
        }

        $out->title("Changements depuis le scan #{$previousId}");

        foreach ([
            'Sites ajoutes' => $diff->sitesAdded,
            'Sites disparus' => $diff->sitesRemoved,
            'Bases ajoutees' => $diff->databasesAdded,
            'Bases supprimees' => $diff->databasesRemoved,
            'Nouvelles orphelines' => $diff->orphansAppeared,
            'Orphelines resorbees' => $diff->orphansResolved,
        ] as $label => $items) {
            if ($items !== []) {
                $out->info($label . ' : ' . implode(', ', $items));
            }
        }

        foreach ($diff->findingsAppeared as $finding) {
            $out->warn('Nouveau constat : ' . $finding['title']);
        }
    }
}
