<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Mysql\GatewayFactory;
use HostingerSpace\Mysql\MysqlCredential;
use HostingerSpace\Transport\SshTransport;

/**
 * Verifie que tout est en place avant de lancer un vrai scan, et dit
 * precisement quoi corriger sinon.
 */
final class DoctorCommand implements Command
{
    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Verifie la configuration, la connexion et les acces MySQL';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Diagnostic');

        $config = $context->config();
        $problems = $config->validate();

        $out->step('Configuration');
        $out->info("Fichier : {$config->sourcePath}");
        $out->info("Mode : {$config->mode()}");

        foreach ($problems as $problem) {
            $out->error($problem);
        }

        if ($problems !== []) {
            $out->line();
            $out->line('  Corrige ces points, puis relance le diagnostic.');

            return 1;
        }

        $out->success('Aucun probleme de configuration.');

        $out->step('Connexion a l\'hebergement');
        $transport = $context->transport();

        try {
            $transport->connect();
        } catch (\Throwable $e) {
            $out->error($e->getMessage());

            return 1;
        }

        $out->success("Connecte : {$transport->label()}");
        $out->info('Dossier personnel : ' . $transport->home());

        if ($transport instanceof SshTransport) {
            $fingerprint = $transport->serverFingerprint();

            if ($fingerprint !== null) {
                if ($config->string('ssh.host_key_fingerprint') === null) {
                    $out->warn("L'empreinte du serveur n'est pas epinglee.");
                    $out->line();
                    $out->line("      Ajoute cette ligne dans la section « ssh » de ta configuration :");
                    $out->line();
                    $out->line("          'host_key_fingerprint' => '{$fingerprint}',");
                    $out->line();
                    $out->line("      Tes identifiants ne partiront plus alors que vers ce serveur precis.");
                    $out->line();
                } else {
                    $out->success("Empreinte du serveur verifiee : {$fingerprint}");
                }
            }
        }

        $this->checkPaths($out, $context);
        $this->checkTools($out, $context);

        return $this->checkMysql($out, $context) ? 0 : 1;
    }

    private function checkPaths(Output $out, Context $context): void
    {
        $out->step('Arborescence');

        $transport = $context->transport();
        $domainsDir = $transport->resolvePath($context->config()->string('paths.domains_dir') ?? '~/domains');

        if (!$transport->isDir($domainsDir)) {
            $out->warn("Dossier des domaines introuvable : {$domainsDir}");
            $out->info("Verifie « paths.domains_dir ». Sur un VPS, l'arborescence differe du mutualise.");

            return;
        }

        $count = count(array_filter(
            $transport->listDir($domainsDir),
            static fn ($entry): bool => $entry->isDir && !str_starts_with($entry->name, '.')
        ));

        $out->success("{$count} domaine(s) dans {$domainsDir}");

        if ($transport->isDir($transport->home() . '/public_html')) {
            $out->success('Domaine principal present dans ~/public_html');
        }
    }

    private function checkTools(Output $out, Context $context): void
    {
        $out->step('Outils disponibles sur le serveur');

        $transport = $context->transport();

        foreach (['mysql ou mariadb' => 'command -v mysql || command -v mariadb', 'find' => 'command -v find', 'du' => 'command -v du'] as $label => $probe) {
            $path = $transport->exec($probe)->trimmed();

            if ($path === '') {
                $out->warn("{$label} : absent");
            } else {
                $out->success("{$label} : " . strtok($path, "\n"));
            }
        }

        $printf = $transport->exec('find . -maxdepth 0 -printf "" >/dev/null 2>&1 && echo oui')->trimmed();

        if ($printf === 'oui') {
            $out->success('find -printf disponible : dates de derniere activite mesurables');
        } else {
            $out->warn("find -printf indisponible : les tailles seront mesurees, mais pas les dates d'activite.");
        }
    }

    private function checkMysql(Output $out, Context $context): bool
    {
        $out->step('Acces MySQL');

        $config = $context->config();
        $user = $config->string('mysql.admin_user');

        if ($user === null || trim($user) === '') {
            $out->warn("Aucun utilisateur MySQL global (« mysql.admin_user ») n'est configure.");
            $out->line();
            $out->line("      Le scan fonctionnera : il utilisera les identifiants trouves dans tes sites.");
            $out->line("      Mais chacun ne voit que ses propres bases, donc une base qu'aucun site");
            $out->line("      n'utilise — precisement une orpheline — risque de rester invisible.");
            $out->line();
            $out->line("      Dans hPanel > Bases de donnees MySQL, cree un utilisateur et rattache-le");
            $out->line("      a toutes tes bases, puis renseigne-le ici.");
            $out->line();

            return true;
        }

        $credential = new MysqlCredential(
            user: $user,
            password: (string) $config->string('mysql.admin_password', ''),
            host: (string) $config->string('mysql.host', 'localhost'),
            port: $config->int('mysql.port', 3306),
            source: 'config',
            isAdmin: true,
        );

        $gateway = (new GatewayFactory($context->transport()))->for($credential);
        $error = $gateway->probe();

        if ($error !== null) {
            $out->error("Connexion MySQL refusee pour {$credential->label()}");
            $out->info($error);
            $gateway->close();

            return false;
        }

        $out->success("Connexion MySQL etablie : {$credential->label()}");

        try {
            $rows = $gateway->query('SHOW DATABASES');
            $visible = array_values(array_filter(
                array_map(static fn (array $row): string => (string) (reset($row) ?: ''), $rows),
                static fn (string $name): bool => !in_array($name, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)
            ));

            $out->success(count($visible) . ' base(s) visible(s) avec cet acces');

            $global = false;

            foreach ($gateway->query('SHOW GRANTS') as $row) {
                if (preg_match('/\bON\s+\*\.\*\s+TO\b/i', (string) (reset($row) ?: '')) === 1) {
                    $global = true;
                }
            }

            if ($global) {
                $out->success('Privileges sur toutes les bases : la detection des orphelines sera fiable.');
            } else {
                $out->warn("Cet acces ne porte pas sur toutes les bases (pas de privilege ON *.*).");
                $out->info("Verifie dans hPanel qu'il est bien rattache a chacune de tes bases.");
            }
        } catch (\Throwable $e) {
            $out->warn("Requete de verification refusee : {$e->getMessage()}");
        } finally {
            $gateway->close();
        }

        $out->line();
        $out->line('  Tout est pret. Lance : php bin/hspace scan');
        $out->line();

        return true;
    }
}
