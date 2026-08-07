<?php

declare(strict_types=1);

/**
 * Point d'entree de l'interface web.
 *
 * Le chemin de la base d'inventaire arrive par l'environnement (HSPACE_DATABASE),
 * pose par « hspace serve ». Le serveur web n'a donc jamais besoin de lire
 * config/config.php, et n'a jamais acces aux identifiants SSH et MySQL.
 */

use HostingerSpace\Config;
use HostingerSpace\Storage\Database;
use HostingerSpace\Web\Kernel;
use HostingerSpace\Web\Response;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// Le serveur integre de PHP sert directement les fichiers existants ; ce
// garde-fou evite qu'une requete vers un fichier statique parte dans le
// routeur si la configuration du serveur change.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file($root . '/public' . $path)) {
    return false;
}

$databasePath = getenv('HSPACE_DATABASE') ?: null;
$demo = getenv('HSPACE_DEMO') === '1';

try {
    if ($databasePath === null) {
        $databasePath = Config::load()->storagePath();
    }

    if (!is_file($databasePath)) {
        throw new RuntimeException(
            "Aucune base d'inventaire à {$databasePath}. Lance d'abord « php bin/hspace scan », " .
            "ou « php bin/hspace demo » pour des données de démonstration."
        );
    }

    (new Kernel(new Database($databasePath), $root . '/templates', $demo))
        ->handle($path, array_map(strval(...), $_GET))
        ->send();
} catch (Throwable $e) {
    (new Response(
        '<!doctype html><meta charset="utf-8"><title>Erreur</title>'
        . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:44rem;margin:4rem auto;padding:0 1.5rem;color:#1c1917}'
        . 'pre{background:#f5f5f4;padding:1rem;border-radius:.5rem;overflow-x:auto;font-size:.9rem}</style>'
        . '<h1>L\'interface n\'a pas pu démarrer</h1><pre>'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</pre>',
        500
    ))->send();
}
