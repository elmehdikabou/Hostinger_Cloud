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
            new DemoCommand(),
        ] as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /** @param array<int,string> $argv */
    public function run(array $argv): int
    {
        $out = new Output();
        $arguments = array_slice($argv, 1);
        $configPath = null;

        // --config=chemin peut apparaitre n'importe ou dans la ligne.
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--config=')) {
                $configPath = substr($argument, 9);
                unset($arguments[$index]);
            }
        }

        // --demo bascule toutes les commandes de lecture sur la base fictive,
        // pour explorer l'outil avant d'avoir branche un hebergement.
        $demo = in_array('--demo', $arguments, true);

        $arguments = array_values($arguments);
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

            if (in_array('-v', $arguments, true) || in_array('--verbose', $arguments, true)) {
                $out->line();
                $out->dim($e->getTraceAsString());
            }

            return 1;
        }
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
        $out->dim('  Pour demarrer : php bin/hspace init, puis php bin/hspace doctor');
        $out->line();
    }
}
