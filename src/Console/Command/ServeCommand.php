<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

/**
 * Lance l'interface web avec le serveur integre de PHP.
 */
final class ServeCommand implements Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Demarre l\'interface web (--demo pour les donnees fictives)';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $root = $context->rootDir();
        $demo = $context->demo;

        $host = '127.0.0.1';
        $port = 8088;
        $databasePath = $context->databasePath();

        if ($demo) {
            if (!is_file($databasePath)) {
                $out->error("Aucune donnee de demonstration. Lance d'abord : php bin/hspace demo");

                return 1;
            }
        } else {
            $config = $context->config();
            $host = (string) $config->string('web.host', $host);
            $port = $config->int('web.port', $port);
        }

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--port=')) {
                $port = (int) substr($argument, 7);
            }

            if (str_starts_with($argument, '--host=')) {
                $host = substr($argument, 7);
            }
        }

        $out->title('Interface web');
        $out->pairs([
            'Adresse' => "http://{$host}:{$port}",
            'Donnees' => $databasePath,
            'Mode' => $demo ? 'demonstration' : 'reel',
        ]);
        $out->line();
        $out->dim('  Ctrl+C pour arreter.');
        $out->line();

        // L'interface lit le chemin de la base dans l'environnement : le
        // serveur web n'a ainsi jamais besoin du fichier de configuration,
        // donc jamais acces aux identifiants SSH et MySQL.
        $environment = [
            'HSPACE_DATABASE' => $databasePath,
            'HSPACE_DEMO' => $demo ? '1' : '0',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $command = sprintf(
            '%s -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            $port,
            escapeshellarg($root . '/public'),
            escapeshellarg($root . '/public/index.php'),
        );

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);

        if (!is_resource($process)) {
            $out->error('Impossible de demarrer le serveur web integre.');

            return 1;
        }

        return proc_close($process);
    }
}
