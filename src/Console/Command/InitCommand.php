<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Config;
use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Console\Prompt;

final class InitCommand implements Command
{
    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Cree la configuration, en mode guide par defaut';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $target = $context->configPath ?? Config::defaultPath();
        $options = $this->options($arguments);
        $prompt = new Prompt($out);

        /*
         * Le mode guide s'active quand personne n'a passe de valeur et qu'on
         * est bien devant un terminal. Une tache planifiee ou un script ne
         * doit jamais se retrouver bloque sur une question.
         */
        $guided = !isset($options['no-interactive'])
            && ($options === [] || isset($options['interactive']))
            && $prompt->isInteractive();

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

        if ($guided) {
            $collected = $this->interview($prompt, $out);

            if ($collected === null) {
                $out->line();
                $out->info('Abandonne. Rien n\'a ete ecrit.');

                return 0;
            }

            $options = $collected;
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

        foreach ([
            'host' => 'host',
            'port' => 'port',
            'user' => 'username',
            'key' => 'private_key_path',
            'passphrase' => 'private_key_passphrase',
            'password' => 'password',
        ] as $option => $key) {
            if (isset($options[$option]) && $options[$option] !== '') {
                $ssh[$key] = $options[$option];
            }
        }

        if ($ssh !== []) {
            $source = $this->replaceInBlock($source, 'ssh', $ssh);

            foreach ($ssh as $key => $value) {
                // Un secret est confirme sans etre reaffiche : l'utilisateur
                // vient de le taper, le lui remontrer ne fait que l'exposer.
                $out->success("ssh.{$key} = " . ($this->isSecret($key) ? '••••••••' : $value));
            }
        }

        $mysql = [];

        foreach (['mysql-user' => 'admin_user', 'mysql-password' => 'admin_password'] as $option => $key) {
            if (isset($options[$option]) && $options[$option] !== '') {
                $mysql[$key] = $options[$option];
            }
        }

        if ($mysql !== []) {
            $source = $this->replaceInBlock($source, 'mysql', $mysql);

            foreach ($mysql as $key => $value) {
                $out->success("mysql.{$key} = " . ($this->isSecret($key) ? '••••••••' : $value));
            }
        }

        return $source;
    }

    private function isSecret(string $key): bool
    {
        return str_contains($key, 'password') || str_contains($key, 'passphrase');
    }

    /**
     * Questionnaire guide. Retourne null si l'utilisateur renonce.
     *
     * @return array<string,string>|null
     */
    private function interview(Prompt $prompt, Output $out): ?array
    {
        $out->line();
        $out->line("  Quelques questions, et la configuration sera prete.");
        $out->dim("  Les valeurs demandees se trouvent dans hPanel. Entree accepte la proposition.");

        $options = [];

        $mode = $prompt->choose('Ou hspace va-t-il tourner ?', [
            'ssh' => "chez toi — il se connectera a Hostinger en SSH",
            'local' => "sur l'hebergement lui-meme — il lira le disque en direct",
        ], 'ssh');

        if ($mode === 'local') {
            $options['local'] = '1';
        } else {
            $out->line();
            $out->step('Acces SSH');
            $out->dim("  hPanel > Avance > Acces SSH");

            $options['host'] = (string) $prompt->ask("Adresse IP du serveur SSH :", required: true);
            $options['port'] = (string) $prompt->ask('Port SSH :', '65002');
            $options['user'] = (string) $prompt->ask("Identifiant (uXXXXXXXXX) :", required: true);

            $method = $prompt->choose('Comment t\'authentifies-tu ?', [
                'cle' => "par cle privee — recommande, rien a stocker en clair",
                'motdepasse' => "par mot de passe",
            ], 'cle');

            if ($method === 'cle') {
                $key = $prompt->ask('Chemin de la cle privee :', $this->defaultKeyPath());

                if ($key !== null) {
                    $options['key'] = $key;

                    if (!is_file(str_replace('~', $this->home(), $key))) {
                        $out->warn("Aucun fichier a cet emplacement. Corrige-le dans la configuration si besoin.");
                    }

                    $passphrase = $prompt->askSecret('Phrase de passe de la cle (Entree si aucune) :');

                    if ($passphrase !== null) {
                        $options['passphrase'] = $passphrase;
                    }
                }
            } else {
                $password = $prompt->askSecret('Mot de passe SSH (invisible pendant la saisie) :');

                if ($password !== null) {
                    $options['password'] = $password;
                }
            }
        }

        $out->line();
        $out->step('Acces MySQL');
        $out->dim("  hPanel > Bases de donnees MySQL");
        $out->line();
        $out->line("  C'est le point qui decide de la fiabilite du resultat. Sur un mutualise,");
        $out->line("  un utilisateur MySQL ne voit que les bases auxquelles il est rattache — or");
        $out->line("  une base orpheline est justement une base qu'aucun compte de site ne voit.");
        $out->line("  Cree un utilisateur rattache a TOUTES tes bases, et indique-le ici.");
        $out->line();

        $mysqlUser = $prompt->ask("Utilisateur MySQL voyant toutes les bases (Entree pour passer) :");

        if ($mysqlUser !== null) {
            $options['mysql-user'] = $mysqlUser;
            $password = $prompt->askSecret('Son mot de passe (invisible pendant la saisie) :');

            if ($password !== null) {
                $options['mysql-password'] = $password;
            }
        } else {
            $out->warn("Sans cet acces, les orphelines seront presentees comme des pistes, pas des faits.");
        }

        $out->line();

        return $prompt->confirm('Ecrire la configuration ?') ? $options : null;
    }

    private function defaultKeyPath(): ?string
    {
        foreach (['id_ed25519', 'id_rsa'] as $name) {
            $path = $this->home() . '/.ssh/' . $name;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function home(): string
    {
        return rtrim((string) (getenv('HOME') ?: getenv('USERPROFILE') ?: ''), '/');
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

    /**
     * Ce qu'il reste a faire — et seulement cela.
     *
     * La liste est deduite de ce qui a reellement ete renseigne : apres le
     * mode guide, reclamer une valeur que l'utilisateur vient de taper ferait
     * douter que sa saisie ait ete prise en compte.
     *
     * @param array<string,string> $options
     */
    private function nextSteps(Output $out, array $options): void
    {
        $remaining = [];
        $local = isset($options['local']);
        $hasAuth = isset($options['key']) || isset($options['password']);

        if ($local) {
            $remaining[] = ["Verifie « paths.domains_dir » si ton arborescence sort de l'ordinaire."];
        } elseif (!$hasAuth) {
            $remaining[] = [
                "Renseigne l'authentification SSH dans le fichier :",
                "soit « private_key_path » (recommande), soit « password ».",
            ];
        }

        if (!isset($options['mysql-user'])) {
            $remaining[] = [
                "Cree dans hPanel > Bases de donnees MySQL un utilisateur rattache a TOUTES",
                "tes bases, et renseigne-le dans « mysql.admin_user ». Sans lui, une base",
                "qu'aucun site n'utilise risque de rester invisible — or c'est exactement",
                "ce qu'on cherche.",
            ];
        }

        $remaining[] = ['Lance le diagnostic : php bin/hspace doctor'];

        $out->line();
        $out->line(count($remaining) === 1 ? '  Il ne reste plus qu\'a :' : '  Etapes suivantes :');
        $out->line();

        foreach ($remaining as $index => $lines) {
            foreach ($lines as $position => $line) {
                $out->line($position === 0
                    ? '   ' . ($index + 1) . '. ' . $line
                    : '      ' . $line);
            }

            $out->line();
        }
    }
}
