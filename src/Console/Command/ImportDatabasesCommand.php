<?php

declare(strict_types=1);

namespace HostingerSpace\Console\Command;

use HostingerSpace\Console\Command;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;
use HostingerSpace\Mysql\KnownDatabases;

/**
 * Enregistre la liste des bases telle qu'affichee par hPanel.
 *
 * C'est la reponse au cas le plus frequent chez Hostinger : chaque base a son
 * propre utilisateur MySQL, aucun compte ne les voit toutes, et l'inventaire
 * par MySQL reste donc incomplet quoi qu'on fasse.
 *
 * Or decider qu'une base ne sert a personne ne demande pas de l'ouvrir : il
 * suffit de connaitre son nom et de le confronter a ce que declarent les
 * sites. La liste collee depuis hPanel rend la question decidable, sans le
 * moindre acces supplementaire.
 */
final class ImportDatabasesCommand implements Command
{
    public function name(): string
    {
        return 'import-databases';
    }

    public function description(): string
    {
        return 'Enregistre la liste des bases copiee depuis hPanel';
    }

    public function run(array $arguments, Output $out, Context $context): int
    {
        $out->title('Liste des bases declarees');

        $target = $this->targetPath($context);
        $file = $arguments[0] ?? null;

        if ($file !== null && !str_starts_with($file, '-')) {
            if (!is_file($file)) {
                $out->error("Fichier introuvable : {$file}");

                return 1;
            }

            $text = (string) file_get_contents($file);
        } else {
            $out->line();
            $out->line('  Ouvre hPanel > Bases de donnees MySQL, selectionne le tableau');
            $out->line('  entier et colle-le ici. Le desordre du collage importe peu.');
            $out->line();
            $out->dim('  Termine par Ctrl+D sur une ligne vide.');
            $out->line();

            $text = (string) stream_get_contents(STDIN);
        }

        $names = KnownDatabases::parse($text);

        if ($names === []) {
            $out->error("Aucun nom de base reconnu dans ce texte.");
            $out->info("Attendu : des lignes comme « uXXXXXXXXX_maBase ».");

            return 1;
        }

        $previous = KnownDatabases::fromFile($target);

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $out->error("Impossible de creer {$directory}");

            return 1;
        }

        $header = "# Liste des bases de donnees, copiee depuis hPanel.\n"
            . "# Regeneree par « php bin/hspace import-databases ».\n"
            . '# ' . count($names) . " bases, le " . date('d/m/Y à H:i') . ".\n\n";

        if (@file_put_contents($target, $header . implode("\n", $names) . "\n") === false) {
            $out->error("Ecriture impossible : {$target}");

            return 1;
        }

        $out->line();
        $out->success(count($names) . " base(s) enregistree(s) dans {$target}");

        $this->reportChanges($out, $previous, $names);

        $out->line();
        $out->line('  Relance le releve pour en tenir compte :');
        $out->line();
        $out->line('      php bin/hspace scan');
        $out->line();

        return 0;
    }

    /**
     * @param array<int,string> $previous
     * @param array<int,string> $names
     */
    private function reportChanges(Output $out, array $previous, array $names): void
    {
        if ($previous === []) {
            return;
        }

        $added = array_diff($names, $previous);
        $removed = array_diff($previous, $names);

        if ($added === [] && $removed === []) {
            $out->info('Liste inchangee depuis le dernier import.');

            return;
        }

        foreach ($added as $name) {
            $out->info("+ {$name}");
        }

        foreach ($removed as $name) {
            $out->info("- {$name} (disparue de hPanel)");
        }
    }

    private function targetPath(Context $context): string
    {
        $configured = $context->config()->string('mysql.known_databases_file');

        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }

        return dirname($context->config()->sourcePath) . '/databases.txt';
    }
}
