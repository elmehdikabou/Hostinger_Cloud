<?php

declare(strict_types=1);

namespace HostingerSpace\Console;

use HostingerSpace\Console\Command\DemoCommand;
use HostingerSpace\Console\Command\DiffCommand;
use HostingerSpace\Console\Command\DoctorCommand;
use HostingerSpace\Console\Command\FindingsCommand;
use HostingerSpace\Console\Command\HistoryCommand;
use HostingerSpace\Console\Command\InitCommand;
use HostingerSpace\Console\Command\OrphansCommand;
use HostingerSpace\Console\Command\PasswordCommand;
use HostingerSpace\Console\Command\ScanCommand;
use HostingerSpace\Console\Command\ServeCommand;
use HostingerSpace\Console\Command\SitesCommand;

final class Application
{
    public const VERSION = '1.0.0';

    /** @var array<string,Command> */
    private array $commands = [];

    public function __construct()
    {
        foreach ([
            new InitCommand(),
            new DoctorCommand(),
            new ScanCommand(),
            new SitesCommand(),
            new OrphansCommand(),
            new FindingsCommand(),
            new HistoryCommand(),
            new DiffCommand(),
            new ServeCommand(),
            new PasswordCommand(),
            new DemoCommand(),
        ] as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /** @param array<int,string> $argv */
    public function run(array $argv): int
    {
        $out = new Output();

        ['arguments' => $arguments, 'config' => $configPath, 'demo' => $demo, 'verbose' => $verbose]
            = self::parseGlobalOptions(array_slice($argv, 1));

        $name = $arguments[0] ?? 'help';

        if (in_array($name, ['help', '--help', '-h', ''], true)) {
            $this->usage($out);

            return 0;
        }

        if (in_array($name, ['--version', '-V'], true)) {
            $out->line('hspace ' . self::VERSION);

            return 0;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $out->error("Commande inconnue : « {$name} »");
            $this->usage($out);

            return 1;
        }

        try {
            return $command->run(array_slice($arguments, 1), $out, new Context($configPath, $demo));
        } catch (\Throwable $e) {
            $out->line();
            $out->error($e->getMessage());

            if ($verbose) {
                $out->line();
                $out->dim($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Separe les options communes des arguments destines a la commande.
     *
     * Les options communes doivent disparaitre de la ligne avant d'atteindre
     * la commande. Sans cela, « hspace diff --demo 1 3 » verrait « --demo »
     * comme premier argument positionnel et tenterait de comparer le scan
     * numero 0 — la commande repondait « il faut au moins deux scans » alors
     * que les deux numeros etaient bien la.
     *
     * @param array<int,string> $argv Arguments, sans le nom du programme.
     *
     * @return array{arguments:array<int,string>,config:?string,demo:bool,verbose:bool}
     */
    public static function parseGlobalOptions(array $argv): array
    {
        $arguments = [];
        $config = null;
        $demo = false;
        $verbose = false;

        foreach ($argv as $argument) {
            match (true) {
                str_starts_with($argument, '--config=') => $config = substr($argument, 9),
                // Bascule les commandes de lecture sur la base fictive, pour
                // explorer l'outil avant d'avoir branche un hebergement.
                $argument === '--demo' => $demo = true,
                $argument === '--verbose', $argument === '-v' => $verbose = true,
                default => $arguments[] = $argument,
            };
        }

        return ['arguments' => $arguments, 'config' => $config, 'demo' => $demo, 'verbose' => $verbose];
    }

    private function usage(Output $out): void
    {
        $out->title('hspace — inventaire d\'un espace Hostinger');
        $out->line();
        $out->line('  Usage : php bin/hspace <commande> [options]');
        $out->line();

        $rows = [];

        foreach ($this->commands as $name => $command) {
            $rows[] = [$name, $command->description()];
        }

        $out->table(['Commande', 'Role'], $rows);
        $out->line();
        $out->dim('  Options communes :');
        $out->dim('    --config=<chemin>   Fichier de configuration a utiliser');
        $out->dim('    --demo              Lit les donnees de demonstration au lieu des tiennes');
        $out->dim('    --verbose           Affiche la trace complete en cas d\'erreur');
        $out->line();
        $out->dim('  Pour demarrer : php bin/hspace init (guide), puis php bin/hspace doctor');
        $out->line();
    }
}
