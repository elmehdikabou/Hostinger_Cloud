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
        return 'Cree la configuration (--host, --port, --user, --key, --local)';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $target = $context->configPath ?? Config::defaultPath();
        $options = $this->options($arguments);

        // Avec --config vers un autre dossier, le modele reste celui du
        // projet : sans ce repli, init echouerait des qu'on range sa
        // configuration ailleurs.
        $example = is_file(dirname($target) . '/config.example.php')
            ? dirname($target) . '/config.example.php'
            : $context->rootDir() . '/config/config.example.php';

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

        $source = file_get_contents($example);

        if ($source === false) {
            $out->error("Modele illisible : {$example}");

            return 1;
        }

        $source = $this->applyOptions($source, $options, $out);

        // 0600 des l'ecriture, et pas apres : le fichier va contenir des
        // identifiants, il ne doit jamais exister en lecture pour tous, meme
        // une fraction de seconde.
        $handle = @fopen($target, 'x');

        if ($handle === false) {
            $out->error("Creation impossible : {$target}");

            return 1;
        }

        @chmod($target, 0o600);
        fwrite($handle, $source);
        fclose($handle);

        $out->success("Configuration creee : {$target}");
        $out->info('Permissions 0600, et le fichier est ignore par git.');

        $this->nextSteps($out, $options);

        return 0;
    }

    /**
     * @param array<int,string> $arguments
     *
     * @return array<string,string>
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if ($argument === '--local') {
                $options['local'] = '1';

                continue;
            }

            if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches) === 1) {
                $options[$matches[1]] = $matches[2];
            }
        }

        return $options;
    }

    /**
     * @param array<string,string> $options
     */
    private function applyOptions(string $source, array $options, Output $out): string
    {
        if (isset($options['local'])) {
            $source = preg_replace("/'mode' => 'ssh'/", "'mode' => 'local'", $source, 1) ?? $source;
            $out->info("Mode « local » : l'outil lira le systeme de fichiers en direct, sans SSH.");

            return $source;
        }

        $ssh = [];

        foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'key' => 'private_key_path'] as $option => $key) {
            if (isset($options[$option]) && $options[$option] !== '') {
                $ssh[$key] = $options[$option];
            }
        }

        if ($ssh !== []) {
            $source = $this->replaceInBlock($source, 'ssh', $ssh);

            foreach ($ssh as $key => $value) {
                $out->success("ssh.{$key} = {$value}");
            }
        }

        if (isset($options['mysql-user']) && $options['mysql-user'] !== '') {
            $source = $this->replaceInBlock($source, 'mysql', ['admin_user' => $options['mysql-user']]);
            $out->success("mysql.admin_user = {$options['mysql-user']}");
        }

        return $source;
    }

    /**
     * Remplace des valeurs a l'interieur d'une section de la configuration.
     *
     * On delimite d'abord la section par comptage de crochets, puis on
     * remplace a l'interieur seulement : « host » existe dans les sections
     * ssh, mysql et web, et un remplacement global les toucherait toutes.
     *
     * @param array<string,string> $values
     */
    private function replaceInBlock(string $source, string $block, array $values): string
    {
        $start = strpos($source, "'{$block}' => [");

        if ($start === false) {
            return $source;
        }

        $depth = 0;
        $end = $start;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    $end = $i + 1;

                    break;
                }
            }
        }

        $section = substr($source, $start, $end - $start);

        foreach ($values as $key => $value) {
            $literal = is_numeric($value) ? $value : "'" . str_replace("'", "\\'", $value) . "'";

            $replaced = preg_replace(
                "/('" . preg_quote($key, '/') . "'\s*=>\s*)[^,\n]+/",
                '${1}' . str_replace('$', '\$', $literal),
                $section,
                1
            );

            if ($replaced !== null) {
                $section = $replaced;
            }
        }

        return substr($source, 0, $start) . $section . substr($source, $end);
    }

    /** @param array<string,string> $options */
    private function nextSteps(Output $out, array $options): void
    {
        $out->line();
        $out->line('  Etapes suivantes :');
        $out->line();

        if (!isset($options['local'])) {
            $out->line("   1. Renseigne l'authentification SSH dans le fichier :");
            $out->line("      soit « private_key_path » (recommande), soit « password ».");
        } else {
            $out->line('   1. Verifie « paths.domains_dir » si ton arborescence est particuliere.');
        }

        $out->line();
        $out->line('   2. Cree dans hPanel > Bases de donnees MySQL un utilisateur rattache a');
        $out->line('      TOUTES tes bases, et renseigne-le dans « mysql.admin_user ». Sans lui,');
        $out->line('      une base qu\'aucun site n\'utilise risque de rester invisible — or');
        $out->line('      c\'est exactement ce qu\'on cherche.');
        $out->line();
        $out->line('   3. php bin/hspace doctor');
        $out->line();
    }
}
