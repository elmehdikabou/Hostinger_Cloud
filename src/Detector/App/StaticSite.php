<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\SiteContext;

/**
 * Dernier recours : site statique, page de parcage, ou dossier vide.
 *
 * Ce detecteur repond toujours, pour qu'aucun dossier ne disparaisse de
 * l'inventaire faute d'avoir ete reconnu.
 */
final class StaticSite implements Detector
{
    public function priority(): int
    {
        return 1000;
    }

    public function detect(SiteContext $context): ?Detection
    {
        $entries = $context->listing();
        $meaningful = array_filter(
            $entries,
            static fn ($entry): bool => !str_starts_with($entry->name, '.')
                && !in_array($entry->name, ['cgi-bin', 'error_log', '.well-known'], true)
        );

        if ($meaningful === []) {
            return new Detection(
                app: 'empty',
                label: 'Dossier vide',
                notes: ["Aucun contenu : domaine reserve mais jamais publie, ou site supprime."],
            );
        }

        // Une page de parcage, c'est une page seule. Des qu'il y a une
        // feuille de style ou un dossier d'images a cote, c'est un vrai site
        // statique, meme minuscule.
        $isDefaultPage = count($meaningful) === 1
            && $context->hasAny('index.html', 'index.htm', 'default.html');

        if ($isDefaultPage) {
            return new Detection(
                app: 'placeholder',
                label: 'Page d\'attente',
                notes: ["Une seule page statique : ressemble a une page « en construction » ou de parcage."],
                evidence: 'index.html',
            );
        }

        return new Detection(
            app: 'static',
            label: 'Site statique',
            notes: ["Aucun code PHP : ce site n'utilise pas de base de donnees."],
        );
    }
}
