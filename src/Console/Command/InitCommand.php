<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Config;
use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

final class InitCommand implements Command
{
    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Cree le fichier de configuration a partir du modele';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $target = $context->configPath ?? Config::defaultPath();
        $example = dirname($target) . '/config.example.php';

        $out->title('Initialisation');

        if (is_file($target)) {
            $out->warn("Le fichier existe deja : {$target}");
            $out->info("Rien n'a ete ecrase. Modifie-le directement, ou supprime-le pour repartir du modele.");

            return 0;
        }

        if (!is_file($example)) {
            $out->error("Modele introuvable : {$example}");

            return 1;
        }

        if (!@copy($example, $target)) {
            $out->error("Copie impossible vers {$target}");

            return 1;
        }

        @chmod($target, 0o600);

        $out->success("Configuration creee : {$target}");
        $out->info('Permissions restreintes a 0600 : ce fichier contiendra des identifiants.');
        $out->line();
        $out->line('  Etapes suivantes :');
        $out->line();
        $out->line('   1. Ouvre hPanel > Avance > Acces SSH et note l\'IP, le port et l\'utilisateur.');
        $out->line('   2. Reporte-les dans la section « ssh » du fichier.');
        $out->line('   3. Cree dans hPanel > Bases de donnees MySQL un utilisateur rattache a TOUTES');
        $out->line('      tes bases, et renseigne-le dans « mysql.admin_user ». Sans lui, les bases');
        $out->line('      orphelines ne pourront pas etre listees de facon fiable.');
        $out->line('   4. Lance : php bin/hspace doctor');
        $out->line();

        return 0;
    }
}
