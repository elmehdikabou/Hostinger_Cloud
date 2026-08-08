<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

/**
 * Liste les bases vues au dernier releve, et par qui elles sont utilisees.
 */
final class DatabasesCommand implements Command
{
    public function name(): string
    {
        return 'databases';
    }

    public function description(): string
    {
        return 'Liste toutes les bases de donnees et les sites qui les utilisent';
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
        $databases = $repository->databases($scanId);
        $complete = (int) $scan['coverage_complete'] === 1;

        // Le nom affichable vient de la table des sites, pas d'un decoupage du
        // chemin : « public_html » n'a pas de dossier parent a montrer, et le
        // raccourci y produisait un « . » incomprehensible.
        $siteNames = [];

        foreach ($repository->sites($scanId) as $site) {
            $siteNames[(string) $site['site_key']] = (string) $site['domain']
                . ($site['mount_path'] !== '/' ? (string) $site['mount_path'] : '');
        }

        $usage = [];

        foreach ($repository->links($scanId) as $link) {
            if ($link['state'] !== 'external') {
                $key = (string) $link['site_key'];
                $usage[(string) $link['database_name']][] = $siteNames[$key] ?? $key;
            }
        }

        $out->title('Bases de donnees — scan #' . $scanId . ' du ' . Output::dateTime((int) $scan['finished_at']));

        if ($databases === []) {
            $out->warn("Aucune base visible. L'acces MySQL n'a rien renvoye.");
            $this->explainCoverage($out, $complete, $context);

            return 1;
        }

        $rows = [];

        foreach ($databases as $database) {
            $name = (string) $database['name'];
            $sites = array_values(array_unique($usage[$name] ?? []));

            // « 0 table, 0 o » decrit une coquille vide. Une base jamais
            // ouverte merite « ? » : son contenu est inconnu, pas nul.
            $measured = (int) ($database['measured'] ?? 1) === 1;

            $rows[] = [
                $name,
                $measured ? (string) $database['table_count'] : '?',
                $measured ? Output::bytes((int) $database['size_bytes']) : '?',
                $sites === []
                    ? ($complete ? 'AUCUN SITE' : 'aucun site vu')
                    : implode(', ', $sites),
                $measured
                    ? Output::since($database['updated_at'] === null ? null : (int) $database['updated_at'])
                    : '?',
            ];
        }

        $out->table(
            ['Base', 'Tables', 'Taille', 'Utilisee par', 'Derniere ecriture'],
            $rows,
            [1 => true, 2 => true]
        );

        $measured = array_values(array_filter(
            $databases,
            static fn (array $d): bool => (int) ($d['measured'] ?? 1) === 1
        ));

        $out->line();

        if ($measured === []) {
            $out->info(count($databases) . ' base(s) — aucune n\'a pu etre ouverte, les tailles sont inconnues.');
        } else {
            $out->info(count($databases) . ' base(s) — ' . Output::bytes(
                array_sum(array_map(static fn (array $d): int => (int) $d['size_bytes'], $measured))
            ) . ' mesures sur ' . count($measured) . ' d\'entre elles');
        }

        if (count($measured) < count($databases)) {
            $out->dim('  « ? » : base connue par son nom seulement, jamais ouverte. Ni vide ni pleine — inconnue.');
        }

        $this->explainCoverage($out, $complete, $context);

        return 0;
    }

    /**
     * Sans acces MySQL global, l'outil ne voit que les bases rattachees aux
     * comptes trouves dans les sites — c'est-a-dire, par construction, des
     * bases utilisees. Trouver une orpheline y est impossible, et le dire
     * vaut mieux que de laisser croire qu'il n'y en a aucune.
     */
    private function explainCoverage(Output $out, bool $complete, Context $context): void
    {
        $out->line();

        if ($complete) {
            $out->success('Liste exhaustive : le rattachement aux sites est donc fiable.');
            $out->dim('  Une base marquee AUCUN SITE ne sert vraiment a aucun site analyse.');
            $out->line();

            return;
        }

        $out->warn('Cette liste est INCOMPLETE, et aucune orpheline ne peut etre trouvee.');
        $out->line();
        $out->line("  Un utilisateur MySQL ne voit que les bases auxquelles il est rattache.");
        $out->line("  Faute d'acces global, l'outil n'a interroge que les comptes lus dans tes");
        $out->line("  sites — donc uniquement des bases deja utilisees. Une base qu'aucun site");
        $out->line("  n'utilise reste invisible : c'est precisement celle qu'on cherche.");
        $out->line();
        $out->line('  Pour obtenir la vue complete :');
        $out->line();
        $out->line('   1. hPanel > Bases de donnees MySQL > Creer un utilisateur');
        $out->line('      (par exemple « inventaire »), puis rattache-le a TOUTES tes bases.');
        $out->line();
        $out->line('   2. Renseigne-le dans ' . $context->config()->sourcePath . ' :');
        $out->line();
        $out->dim("          'mysql' => [");
        $out->dim("              'admin_user' => 'uXXXXXXXXX_inventaire',");
        $out->dim("              'admin_password' => '…',");
        $out->line();
        $out->line('   3. php bin/hspace scan');
        $out->line();
    }
}
