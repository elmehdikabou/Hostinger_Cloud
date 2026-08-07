<?php

declare(strict_types=1);

namespace HostingerSpace\Console;

/**
 * Questions posees dans le terminal.
 *
 * Les secrets sont lus avec l'echo desactive : un mot de passe tape ici ne
 * s'affiche pas, ne part pas dans l'historique du shell, et n'apparait dans
 * aucune liste de processus — contrairement a un « --password=... » passe en
 * argument, que n'importe quel autre compte de la machine pourrait lire.
 */
final class Prompt
{
    /**
     * @param resource  $input
     * @param bool|null $interactive Force le mode ; null pour le deduire de
     *                               l'entree. Sert aux tests, qui n'ont pas
     *                               de terminal a leur disposition.
     */
    public function __construct(
        private readonly Output $out,
        private readonly mixed $input = STDIN,
        private readonly ?bool $interactive = null,
    ) {
    }

    /**
     * Le mode guide n'a de sens que devant un vrai terminal. Sans cela, un
     * script ou une tache planifiee resterait bloque sur une question que
     * personne ne lira.
     */
    public function isInteractive(): bool
    {
        if ($this->interactive !== null) {
            return $this->interactive;
        }

        return is_resource($this->input)
            && function_exists('posix_isatty')
            && @posix_isatty($this->input);
    }

    public function ask(string $question, ?string $default = null, bool $required = false): ?string
    {
        while (true) {
            $suffix = $default !== null && $default !== '' ? ' ' . $this->out->paint("[{$default}]", '2') : '';
            $this->out->write('  ' . $question . $suffix . ' ');

            $answer = $this->readLine();

            if ($answer === '') {
                if ($default !== null && $default !== '') {
                    return $default;
                }

                if (!$required) {
                    return null;
                }

                $this->out->error('Cette valeur est obligatoire.');

                continue;
            }

            return $answer;
        }
    }

    /** Lecture sans echo. Retourne null si l'utilisateur ne saisit rien. */
    public function askSecret(string $question): ?string
    {
        $this->out->write('  ' . $question . ' ');

        $restore = $this->silenceTerminal();
        $answer = $this->readLine();
        $restore();

        // Le retour a la ligne tape par l'utilisateur n'a pas ete affiche.
        $this->out->line();

        return $answer === '' ? null : $answer;
    }

    /**
     * @param array<string,string> $choices Valeur => libelle.
     */
    public function choose(string $question, array $choices, string $default): string
    {
        $keys = array_keys($choices);

        $this->out->line();
        $this->out->line('  ' . $question);

        foreach ($choices as $value => $label) {
            $marker = $value === $default ? $this->out->paint(' (defaut)', '2') : '';
            $this->out->line('    ' . $this->out->paint($value, '1') . ' — ' . $label . $marker);
        }

        while (true) {
            $answer = $this->ask('Ton choix', $default);

            if ($answer !== null && in_array($answer, $keys, true)) {
                return $answer;
            }

            $this->out->error('Reponse attendue : ' . implode(', ', $keys));
        }
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $answer = $this->ask($question . ($default ? ' [O/n]' : ' [o/N]'));

        if ($answer === null) {
            return $default;
        }

        return in_array(mb_strtolower($answer), ['o', 'oui', 'y', 'yes'], true);
    }

    private function readLine(): string
    {
        $line = fgets($this->input);

        return $line === false ? '' : trim($line);
    }

    /**
     * Coupe l'echo du terminal et rend la fonction qui le retablit.
     *
     * Le retablissement est aussi enregistre a l'extinction : si le processus
     * s'arrete pendant la saisie (Ctrl+C, erreur), le terminal ne reste pas
     * muet pour la suite de la session.
     *
     * @return callable(): void
     */
    private function silenceTerminal(): callable
    {
        if (!$this->isInteractive() || stripos(PHP_OS_FAMILY, 'win') === 0) {
            return static function (): void {
            };
        }

        $previous = trim((string) @shell_exec('stty -g 2>/dev/null'));

        if ($previous === '') {
            return static function (): void {
            };
        }

        @shell_exec('stty -echo 2>/dev/null');

        $restored = false;
        $restore = static function () use ($previous, &$restored): void {
            if (!$restored) {
                $restored = true;
                @shell_exec('stty ' . escapeshellarg($previous) . ' 2>/dev/null');
            }
        };

        register_shutdown_function($restore);

        return $restore;
    }
}
