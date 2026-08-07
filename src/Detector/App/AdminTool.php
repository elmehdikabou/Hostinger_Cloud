<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\SiteContext;

/**
 * Outils d'administration de base deposes dans un site.
 *
 * Un phpMyAdmin ou un adminer.php oublie dans un coin est une porte ouverte
 * sur toutes les bases du compte : il merite d'apparaitre dans l'inventaire
 * avec un signalement, pas d'etre range parmi les sites ordinaires.
 */
final class AdminTool implements Detector
{
    public function priority(): int
    {
        return 5;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if ($context->hasAll('libraries/config.default.php', 'index.php')
            || $context->hasAny('config.sample.inc.php', 'phpmyadmin.css.php')) {
            return new Detection(
                app: 'phpmyadmin',
                label: 'phpMyAdmin',
                notes: [
                    "phpMyAdmin accessible publiquement : c'est un acces direct a tes bases. " .
                    "A proteger par mot de passe ou a supprimer s'il ne sert plus.",
                ],
                evidence: 'libraries/config.default.php',
            );
        }

        foreach ($context->listing() as $entry) {
            $name = strtolower($entry->name);

            if (!$entry->isDir && (str_starts_with($name, 'adminer') && str_ends_with($name, '.php'))) {
                return new Detection(
                    app: 'adminer',
                    label: 'Adminer',
                    notes: [
                        "Adminer ({$entry->name}) est accessible publiquement : c'est un acces direct " .
                        "a tes bases. A supprimer s'il ne sert plus.",
                    ],
                    evidence: $entry->name,
                );
            }
        }

        return null;
    }
}
