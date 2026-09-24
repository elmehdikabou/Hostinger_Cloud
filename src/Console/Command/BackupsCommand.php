<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Backup\BackupArtifact;
use HostingerSpace\Backup\BackupAudit;
use HostingerSpace\Backup\BackupScanner;
use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Model\Severity;
use HostingerSpace\Scanner\SiteScanner;

/**
 * Verifie les sauvegardes presentes sur le disque.
 *
 * Deux questions, et la seconde passe devant : les sites sont-ils sauvegardes,
 * et ces sauvegardes sont-elles telechargeables par n'importe qui ? Sans
 * sauvegarde on risque de perdre ses donnees ; avec une sauvegarde exposee on
 * les a deja livrees, sans le savoir.
 */
final class BackupsCommand implements Command
{
    public function name(): string
    {
        return 'backups';
    }

    public function description(): string
    {
        return 'Verifie les sauvegardes : presence, anciennete, exposition web';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Sauvegardes');

        $config = $context->config();
        $transport = $context->transport();
        $transport->connect();

        $out->step('Recherche des sites');
        $sites = (new SiteScanner($transport, $config))->scan();
        $out->step(count($sites) . ' site(s)');

        $out->step('Recherche des sauvegardes');
        $artifacts = (new BackupScanner($transport))->scan($sites);
        $out->step(count($artifacts) . ' sauvegarde(s) trouvee(s)');

        $findings = (new BackupAudit($config->int('analysis.stale_backup_days', 30)))
            ->findings($artifacts, $sites);

        $exposed = array_values(array_filter($artifacts, static fn (BackupArtifact $a): bool => $a->webReachable));

        $this->showExposed($out, $exposed);
        $this->showInventory($out, $artifacts);
        $this->showMissing($out, $findings);

        $out->line();
        $out->dim('  Les sauvegardes automatiques de Hostinger ne sont pas sur le disque :');
        $out->dim('  hPanel > Fichiers > Sauvegardes. Verifie leur date la aussi.');
        $out->line();

        return $exposed === [] ? 0 : 1;
    }

    /** @param array<int,BackupArtifact> $exposed */
    private function showExposed(Output $out, array $exposed): void
    {
        if ($exposed === []) {
            $out->line();
            $out->success('Aucune sauvegarde telechargeable depuis le web.');

            return;
        }

        $out->title('A corriger tout de suite');
        $out->line();
        $out->error(count($exposed) . ' sauvegarde(s) sont sous une racine web.');
        $out->line();
        $out->line('  Quiconque devine leur nom peut les telecharger. Un dump SQL livre');
        $out->line('  la base entiere — comptes, adresses, mots de passe haches — sans');
        $out->line('  qu\'aucune faille soit necessaire, et sans laisser de trace.');
        $out->line();

        $rows = [];

        foreach ($exposed as $artifact) {
            $rows[] = [
                $artifact->path,
                $artifact->kind,
                $artifact->kind === 'dossier' ? '—' : Output::bytes($artifact->sizeBytes),
                $artifact->modifiedAt === null ? '?' : Output::since($artifact->modifiedAt),
            ];
        }

        $out->table(['Chemin', 'Nature', 'Taille', 'Modifiee'], $rows, [2 => true]);
        $out->line();
        $out->line('  Mets-les a l\'abri, hors de toute racine web :');
        $out->line();
        $out->dim('      mkdir -p ~/sauvegardes && mv CHEMIN ~/sauvegardes/');
        $out->line();
    }

    /** @param array<int,BackupArtifact> $artifacts */
    private function showInventory(Output $out, array $artifacts): void
    {
        if ($artifacts === []) {
            $out->line();
            $out->warn('Aucune sauvegarde trouvee sur le disque.');

            return;
        }

        $out->title('Toutes les sauvegardes');

        $rows = [];
        $total = 0;

        foreach (array_slice($artifacts, 0, 40) as $artifact) {
            $total += $artifact->sizeBytes;

            $rows[] = [
                $artifact->path,
                $artifact->kind === 'dossier' ? '—' : Output::bytes($artifact->sizeBytes),
                $artifact->modifiedAt === null ? '?' : Output::since($artifact->modifiedAt),
                $artifact->webReachable ? 'EXPOSEE' : '',
            ];
        }

        $out->table(['Chemin', 'Taille', 'Modifiee', 'Etat'], $rows, [1 => true]);

        if (count($artifacts) > 40) {
            $out->dim('    … et ' . (count($artifacts) - 40) . ' autre(s).');
        }

        $out->line();
        $out->info(count($artifacts) . ' sauvegarde(s), ' . Output::bytes($total) . ' au total');
    }

    /** @param array<int,\HostingerSpace\Model\Finding> $findings */
    private function showMissing(Output $out, array $findings): void
    {
        $missing = array_values(array_filter(
            $findings,
            static fn ($f): bool => $f->kind === \HostingerSpace\Model\FindingKind::NoBackup
        ));

        if ($missing === []) {
            return;
        }

        $out->title('Sites sans sauvegarde sur le disque');
        $out->line();

        foreach (array_slice($missing, 0, 30) as $finding) {
            $out->warn($finding->title);
        }

        if (count($missing) > 30) {
            $out->dim('    … et ' . (count($missing) - 30) . ' autre(s).');
        }

        $out->line();
        $out->dim('  Cela ne veut pas dire qu\'ils ne sont pas sauvegardes : Hostinger tient');
        $out->dim('  ses propres sauvegardes, invisibles depuis le disque.');
    }
}
