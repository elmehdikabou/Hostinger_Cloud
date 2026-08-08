<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Console\Prompt;
use HostingerSpace\Web\Auth;

/**
 * Definit le mot de passe de l'interface web.
 *
 * Seul le condense est ecrit dans la configuration : meme en lisant le
 * fichier, on ne retrouve pas le mot de passe.
 */
final class PasswordCommand implements Command
{
    private const MIN_LENGTH = 10;

    public function name(): string
    {
        return 'passwd';
    }

    public function description(): string
    {
        return 'Definit le mot de passe de l\'interface web';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Mot de passe de l\'interface');

        $prompt = new Prompt($out);

        // « --generate » ne demande rien a personne : il fonctionne donc aussi
        // depuis une tache planifiee ou un script de deploiement.
        if (!$prompt->isInteractive() && !in_array('--generate', $arguments, true)) {
            $out->error("Cette commande demande un terminal.");
            $out->info("Sans terminal, utilise « php bin/hspace passwd --generate » :");
            $out->info("un mot de passe solide sera tire au hasard et affiche une fois.");

            return 1;
        }

        $out->line();
        $out->line("  L'interface publie la carte de ton hebergement : noms des bases,");
        $out->line("  utilisateurs MySQL, chemins, faiblesses reperees. Choisis un mot de");
        $out->line("  passe long — il n'a pas a etre memorisable, un gestionnaire suffit.");
        $out->line();

        $generate = in_array('--generate', $arguments, true);

        /*
         * Beaucoup d'hebergements mutualises desactivent shell_exec, exec et
         * proc_open : sans elles, impossible d'appeler stty, donc impossible
         * de masquer une saisie. Plutot que d'afficher le mot de passe en
         * clair pendant la frappe, on en tire un au hasard : rien n'est tape,
         * donc rien ne s'affiche par accident, et il est plus solide que ce
         * qu'on aurait choisi soi-meme.
         */
        if (!$generate && !$prompt->canHideInput()) {
            $out->warn("Ce serveur ne permet pas de masquer une saisie (stty indisponible).");
            $out->line();
            $out->line("  Un mot de passe tape ici resterait affiche a l'ecran. Je peux");
            $out->line("  en engendrer un au hasard : il ne sera montre qu'une fois,");
            $out->line("  a copier dans ton gestionnaire de mots de passe.");
            $out->line();

            if (!$prompt->confirm('Engendrer un mot de passe solide ?')) {
                $out->line();
                $out->info("Rien n'a ete change. Relance avec « --generate » quand tu veux,");
                $out->info("ou definis « web.password_hash » toi-meme dans la configuration.");

                return 0;
            }

            $generate = true;
        }

        if ($generate) {
            $password = self::generate();
        } else {
            $password = $prompt->askSecret('Nouveau mot de passe :');

            if ($password === null) {
                $out->info('Abandonne.');

                return 0;
            }

            if (mb_strlen($password) < self::MIN_LENGTH) {
                $out->error('Trop court : ' . self::MIN_LENGTH . ' caracteres au minimum.');

                return 1;
            }

            if ($prompt->askSecret('Confirme le mot de passe :') !== $password) {
                $out->error('Les deux saisies different.');

                return 1;
            }
        }

        $hash = Auth::hash($password);
        $path = $context->config()->sourcePath;
        $written = $this->write($path, $hash);

        $out->line();

        if ($generate) {
            $out->line('  ' . $out->paint('Ton mot de passe — note-le maintenant, il ne sera plus affiche :', '1'));
            $out->line();
            $out->line('      ' . $out->paint($password, '1;36'));
            $out->line();
        }

        $out->success('Condense calcule (' . $this->algorithmName($hash) . ').');

        if ($written) {
            $out->success("Ecrit dans {$path}");
            $out->line();
            $out->line("  L'interface est desormais protegee. Les sessions ouvertes avec");
            $out->line("  l'ancien mot de passe sont invalidees.");
        } else {
            $out->warn("Le fichier n'a pas pu etre modifie automatiquement.");
            $out->line();
            $out->line("  Ajoute cette ligne dans la section « web » de {$path} :");
            $out->line();
            $out->line("      'password_hash' => '{$hash}',");
        }

        $out->line();

        return 0;
    }

    /**
     * Ecrit le condense dans la section « web » de la configuration.
     *
     * Le fichier est reecrit en place plutot que regenere : on ne veut pas
     * perdre les commentaires ni les reglages deja personnalises.
     */
    private function write(string $path, string $hash): bool
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            return false;
        }

        $literal = "'" . str_replace("'", "\\'", $hash) . "'";
        $start = strpos($source, "'web' => [");

        if ($start === false) {
            return false;
        }

        $end = strpos($source, ']', $start);

        if ($end === false) {
            return false;
        }

        $section = substr($source, $start, $end - $start);

        if (str_contains($section, "'password_hash'")) {
            $section = preg_replace(
                "/('password_hash'\s*=>\s*)[^,\n]+/",
                '${1}' . str_replace('$', '\$', $literal),
                $section,
                1
            ) ?? $section;
        } else {
            $section = rtrim($section, " \n") . "\n        'password_hash' => {$literal},\n    ";
        }

        $updated = substr($source, 0, $start) . $section . substr($source, $end);

        return @file_put_contents($path, $updated) !== false;
    }

    /**
     * Mot de passe tire au hasard.
     *
     * L'alphabet exclut les caracteres qu'on confond en les recopiant a
     * l'oeil — 0/O, 1/l/I — parce que celui-ci sera lu a l'ecran puis colle
     * dans un gestionnaire, et qu'une erreur de lecture ferme la porte.
     */
    private static function generate(int $length = 24): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    private function algorithmName(string $hash): string
    {
        return match (password_get_info($hash)['algoName'] ?? '') {
            'bcrypt' => 'bcrypt',
            'argon2id' => 'argon2id',
            'argon2i' => 'argon2i',
            default => 'algorithme par defaut de PHP',
        };
    }
}
