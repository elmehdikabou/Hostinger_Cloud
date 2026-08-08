<?php

declare(strict_types=1);

/**
 * Point d'entree de l'interface web.
 *
 * Ce dossier « public » est le seul a exposer au serveur web. La
 * configuration, la base d'inventaire et le code vivent un cran au-dessus :
 * meme mal servi, ce dossier ne peut pas livrer config/config.php.
 *
 * Lance par « hspace serve », le chemin de la base arrive par l'environnement
 * et la configuration n'est pas lue du tout : le serveur local n'a alors
 * aucun acces aux identifiants SSH et MySQL. Deploye sur un hebergement,
 * l'environnement est vide et la configuration est lue pour y trouver la base
 * et le mot de passe de l'interface.
 */

use HostingerSpace\Config;
use HostingerSpace\Storage\Database;
use HostingerSpace\Web\Auth;
use HostingerSpace\Web\Kernel;
use HostingerSpace\Web\Response;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Le serveur integre de PHP sert directement les fichiers existants ; ce
// garde-fou evite qu'une requete vers un fichier statique parte dans le
// routeur si la configuration du serveur change.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file($root . '/public' . $path)) {
    return false;
}

$databasePath = getenv('HSPACE_DATABASE') ?: null;
$demo = getenv('HSPACE_DEMO') === '1';

try {
    $passwordHash = null;

    if ($databasePath === null || !$demo) {
        $config = Config::load();
        $databasePath ??= $config->storagePath();
        $passwordHash = $config->string('web.password_hash');
    }

    if (!is_file($databasePath)) {
        /*
         * Aucun releve n'a encore ete enregistre. Ce n'est pas une panne mais
         * une installation inachevee : on le dit clairement, sans divulguer le
         * chemin du fichier a un visiteur de passage.
         */
        (new Response(
            '<!doctype html><meta charset="utf-8"><title>Inventaire vide — hspace</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<link rel="stylesheet" href="/assets/app.css">'
            . '<div class="gate"><div class="gate-card gate-card--wide">'
            . '<h1>Aucun relevé pour l\'instant</h1>'
            . '<p class="subtitle">L\'application est en place, mais elle n\'a encore rien à montrer.</p>'
            . '<p>Depuis un terminal, à la racine du projet :</p>'
            . '<code class="command">php bin/hspace scan</code>'
            . '<p class="muted">Recharge cette page ensuite.</p>'
            . '</div></div>',
            503
        ))->send();

        return;
    }

    (new Kernel(
        new Database($databasePath),
        $root . '/templates',
        $demo,
        Auth::fromRequest($passwordHash, $_SERVER),
    ))
        ->handle(
            $path,
            array_map(strval(...), $_GET),
            array_map(strval(...), $_POST),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        )
        ->send();
} catch (Throwable $e) {
    /*
     * Le detail part dans le journal du serveur avant toute chose. Sans cette
     * ligne, la page renvoyait le proprietaire vers « les journaux » alors que
     * rien n'y etait jamais ecrit : un cul-de-sac au pire moment.
     */
    error_log('hspace: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');

    /*
     * Le detail n'est montre qu'a la machine locale. Sur un hebergement, un
     * message d'erreur revelerait des chemins et des noms de fichiers a qui
     * n'a pas encore passe la page de connexion.
     */
    $detail = Auth::isLocalClient($_SERVER)
        ? htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : "Le détail a été écrit dans le journal d'erreurs du serveur.\n\n"
            . "Pour le lire, en SSH à la racine du projet :\n"
            . "    php bin/hspace doctor\n"
            . "    tail -n 20 ../error_log";

    (new Response(
        '<!doctype html><meta charset="utf-8"><title>Erreur</title>'
        . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:44rem;margin:4rem auto;padding:0 1.5rem;color:#1c1917}'
        . 'pre{background:#f5f5f4;padding:1rem;border-radius:.5rem;overflow-x:auto;font-size:.9rem;white-space:pre-wrap}</style>'
        . '<h1>L\'interface n\'a pas pu démarrer</h1><pre>' . $detail . '</pre>',
        500
    ))->send();
}
