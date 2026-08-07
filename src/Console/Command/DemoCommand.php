<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Demo\FakeAccount;
use HostingerSpace\Scanner\ScanResult;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Storage\Database;
use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Transport\LocalTransport;

/**
 * Fabrique un compte fictif et enregistre un scan complet.
 *
 * Permet de voir l'interface remplie de donnees credibles avant d'avoir
 * branche le moindre identifiant — et de reproduire un probleme sans
 * toucher a un hebergement reel.
 */
final class DemoCommand implements Command
{
    public function name(): string
    {
        return 'demo';
    }

    public function description(): string
    {
        return 'Genere un compte fictif et un scan de demonstration';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Demonstration');

        $root = $context->rootDir() . '/var/demo';
        $out->step('Creation du compte fictif dans ' . $root);

        $account = new FakeAccount($root);
        $account->build();

        $config = Config::fromArray([
            'mode' => 'local',
            'paths' => [
                'home' => $root,
                'domains_dir' => $root . '/domains',
                'ignore' => ['node_modules', 'vendor', 'cache'],
            ],
            'analysis' => ['max_depth' => 3, 'abandoned_after_days' => 180],
        ]);

        $transport = new LocalTransport($root);

        $out->step('Analyse');
        $sites = (new SiteScanner($transport, $config))->scan();

        // Le compte fictif est fraichement ecrit : ses dates de modification
        // sont celles de maintenant. On les vieillit pour que les signes
        // d'abandon soient visibles dans la demonstration.
        $this->ageFiles($sites);

        $databasePath = $context->rootDir() . '/var/demo.sqlite';
        @unlink($databasePath);

        $repository = new ScanRepository(new Database($databasePath));

        // Trois releves espaces dans le temps plutot qu'un seul : sans
        // historique, les pages Historique et Comparaison n'auraient rien a
        // montrer, alors que c'est la moitie de l'interet de l'outil.
        $moments = [
            ['days' => 62, 'drop' => ['u998877_test'], 'add' => []],
            ['days' => 29, 'drop' => [], 'add' => []],
            ['days' => 0, 'drop' => ['u998877_ancienne'], 'add' => ['u998877_export_2026' => 5_242_880]],
        ];

        $scanId = 0;
        $analysis = null;
        $inventory = FakeAccount::inventory();

        foreach ($moments as $moment) {
            $inventory = $this->inventoryAt($moment['drop'], $moment['add']);
            $analysis = (new Linker(180))->analyse($sites, $inventory);
            $finishedAt = time() - ($moment['days'] * 86_400);

            $scanId = $repository->save(new ScanResult(
                startedAt: $finishedAt - 42,
                finishedAt: $finishedAt,
                host: 'demonstration (compte fictif)',
                mode: 'local',
                sites: $sites,
                inventory: $inventory,
                analysis: $analysis,
                errors: [],
            ));

            $out->info("Releve #{$scanId} — " . count($inventory->databases) . ' bases, '
                . count($analysis->orphans) . ' orpheline(s)');
        }

        $out->success(count($moments) . " releves de demonstration enregistres.");
        $out->line();
        $out->pairs([
            'Sites' => (string) count($sites),
            'Bases' => (string) count($inventory->databases),
            'Orphelines' => implode(', ', $analysis->orphans),
            'Constats' => (string) count($analysis->findings),
            'Base de donnees' => $databasePath,
        ]);

        $out->line();
        $out->line('  Pour explorer l\'interface avec ces donnees :');
        $out->line();
        $out->line('      php bin/hspace serve --demo');
        $out->line();

        return 0;
    }

    /**
     * Etat des bases a un instant donne du scenario.
     *
     * @param array<int,string>    $drop Bases pas encore creees, ou deja supprimees.
     * @param array<string,int>    $add  Bases apparues depuis, avec leur taille.
     */
    private function inventoryAt(array $drop, array $add): \HostingerSpace\Mysql\DatabaseInventory
    {
        $base = FakeAccount::inventory();
        $databases = $base->databases;

        foreach ($drop as $name) {
            unset($databases[$name]);
        }

        foreach ($add as $name => $size) {
            $info = new \HostingerSpace\Mysql\DatabaseInfo($name);
            $info->tableCount = 3;
            $info->sizeBytes = $size;
            $info->updatedAt = time() - 86_400;
            $info->charset = 'utf8mb4';
            $info->addSource('u998877_demo@localhost (acces global)');
            $databases[$name] = $info;
        }

        ksort($databases, SORT_NATURAL | SORT_FLAG_CASE);

        return new \HostingerSpace\Mysql\DatabaseInventory($databases, $base->probes, true);
    }

    /** @param array<int,\HostingerSpace\Model\Site> $sites */
    private function ageFiles(array $sites): void
    {
        $ages = [
            'photos-durand.com' => 2_400,
            'annuaire-2011.net' => 4_100,
            'association-loire.org' => 1_800,
            'projet-abandonne.com' => 900,
            'boutique-velo.fr' => 40,
            'boulangerie-martin.fr' => 3,
            'api-interne.dev' => 12,
        ];

        foreach ($sites as $site) {
            $days = $ages[$site->domain] ?? 60;
            $site->lastModifiedAt = time() - ($days * 86_400);
            $site->sizeBytes = max($site->sizeBytes, 1024 * (100 + strlen($site->domain) * 37));
            $site->fileCount = max($site->fileCount, 12);
        }
    }
}
