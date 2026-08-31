<?php

declare(strict_types=1);

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Demo\FakeAccount;
use HostingerSpace\Scanner\ScanResult;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Storage\Database;
use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Transport\LocalTransport;
use HostingerSpace\Web\Fmt;
use HostingerSpace\Web\Kernel;

$account = FakeAccount::create();
$transport = new LocalTransport($account->root);
$config = Config::fromArray([
    'mode' => 'local',
    'paths' => ['home' => $account->root, 'domains_dir' => $account->root . '/domains', 'ignore' => []],
    'analysis' => ['max_depth' => 3],
]);

$sites = (new SiteScanner($transport, $config))->scan();
$inventory = FakeAccount::inventory();

$path = sys_get_temp_dir() . '/hspace-web-' . bin2hex(random_bytes(6)) . '.sqlite';
$database = new Database($path);
$repository = new ScanRepository($database);

foreach ([1, 2] as $round) {
    $repository->save(new ScanResult(
        startedAt: 1_770_000_000 + $round,
        finishedAt: 1_770_000_100 + $round,
        host: 'test',
        mode: 'local',
        sites: $sites,
        inventory: $inventory,
        analysis: (new Linker(180))->analyse($sites, $inventory),
    ));
}

$kernel = new Kernel($database, dirname(__DIR__, 2) . '/templates', demo: true);

$pages = [
    '/' => [],
    '/sites' => [],
    '/sites' => ['app' => 'wordpress'],
    '/databases' => [],
    '/orphans' => [],
    '/findings' => [],
    '/findings-critical' => ['severity' => 'critical'],
    '/domains' => [],
    '/scans' => [],
    '/diff' => [],
];

test('Chaque page se rend sans erreur PHP', function () use ($kernel, $pages): void {
    foreach ($pages as $route => $query) {
        $route = str_starts_with($route, '/findings-') ? '/findings' : $route;
        $response = $kernel->handle($route, $query);

        assertSame(200, $response->status, "la page {$route} devrait repondre 200");
        assertContains('<!doctype html>', $response->body, "la page {$route} devrait etre une page complete");
        assertFalse(
            str_contains($response->body, 'Fatal error') || str_contains($response->body, 'Warning:'),
            "la page {$route} ne doit contenir aucune erreur PHP"
        );
    }
});

test('La fiche d un site se rend', function () use ($kernel): void {
    $response = $kernel->handle('/site', ['key' => 'domains/boulangerie-martin.fr/public_html']);

    assertSame(200, $response->status);
    assertContains('boulangerie-martin.fr', $response->body);
    assertContains('u998877_boulangerie', $response->body);
});

test('La fiche d une base se rend avec sa marche a suivre', function () use ($kernel): void {
    $response = $kernel->handle('/database', ['name' => 'u998877_wordpress_old']);

    assertSame(200, $response->status);
    assertContains('mysqldump', $response->body, 'une orpheline doit rappeler la sauvegarde');
    assertContains('ne supprime jamais rien', $response->body);
});

test('Une adresse inconnue renvoie 404 et non une erreur', function () use ($kernel): void {
    assertSame(404, $kernel->handle('/nawak', [])->status);
    assertSame(404, $kernel->handle('/site', ['key' => 'inexistant'])->status);
    assertSame(404, $kernel->handle('/database', ['name' => 'inexistante'])->status);
});

test("Aucun mot de passe n'apparait dans les pages", function () use ($kernel): void {
    $body = $kernel->handle('/site', ['key' => 'domains/boulangerie-martin.fr/public_html'])->body
        . $kernel->handle('/sites', [])->body
        . $kernel->handle('/databases', [])->body;

    foreach (["e5t#Un'MotDePasse", 'photo2019', 'velo123', 'vieuxmdp'] as $secret) {
        assertFalse(str_contains($body, $secret), "le mot de passe « {$secret} » ne doit pas etre affiche");
    }
});

test('Le contenu injecte est echappe', function (): void {
    // Un nom de base ou de domaine vient de l'hebergement, pas de nous : il
    // est traite comme du texte, jamais comme du balisage.
    $dangerous = '<script>alert(1)</script>';

    assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', Fmt::e($dangerous));
    assertFalse(str_contains(Fmt::databaseUrl($dangerous), '<script>'));
    assertFalse(str_contains(Fmt::siteUrl($dangerous), '<script>'));
});

test('Les largeurs de barre passent par des classes, jamais par du style en ligne', function (): void {
    // La politique de securite de la page interdit le style en ligne : une
    // largeur calculee doit donc sortir sous forme de classe.
    assertSame('w0', Fmt::barClass(0.0));
    assertSame('w5', Fmt::barClass(0.001), 'une valeur minuscule doit rester visible');
    assertSame('w50', Fmt::barClass(0.5));
    assertSame('w100', Fmt::barClass(1.0));
    assertSame('w100', Fmt::barClass(3.0), 'un ratio hors bornes est ramene a 100 %');
    assertSame('w0', Fmt::barClass(-1.0));
});

test("Le selecteur de releve ne fige pas la navigation sur un vieux scan", function () use ($kernel, $repository): void {
    $scans = $repository->scans();
    $latest = (int) $scans[0]['id'];
    $older = (int) $scans[1]['id'];

    // Sur le dernier releve, les liens restent courts.
    $kernel->handle('/', []);
    assertSame('/sites', Fmt::url('/sites'));

    // Sur un releve ancien, ils emportent le numero, sinon un clic
    // ramenerait sans prevenir sur les donnees du jour.
    $kernel->handle('/', ['scan' => (string) $older]);
    assertSame('/sites?scan=' . $older, Fmt::url('/sites'));

    $kernel->handle('/', ['scan' => (string) $latest]);
    assertSame('/sites', Fmt::url('/sites'));
});

test('Une base jamais ouverte ne se presente jamais comme vide', function () use ($sites): void {
    /*
     * Le cas d'un mutualise Hostinger : les bases sont connues par la seule
     * liste hPanel, aucune n'a pu etre ouverte. Elles portent alors zero table
     * et zero octet — la description exacte d'une coquille vide.
     *
     * L'interface disait donc « 43 bases totalement vides, les plus sures a
     * supprimer », et le tableau de bord « aucune base inutilisee » juste sous
     * le chiffre 43. Une base pleine designee comme sure a supprimer est la
     * seule erreur de cet outil qui detruise des donnees.
     */
    $databases = [];

    foreach (['u1_alpha', 'u1_t22AF', 'u1_Ceab4'] as $nom) {
        $databases[$nom] = new \HostingerSpace\Mysql\DatabaseInfo($nom);
    }

    $inventaire = new \HostingerSpace\Mysql\DatabaseInventory(
        $databases,
        probes: [],
        complete: true,
        declared: true,
    );

    $chemin = sys_get_temp_dir() . '/hspace-inconnu-' . bin2hex(random_bytes(6)) . '.sqlite';
    $base = new \HostingerSpace\Storage\Database($chemin);

    (new ScanRepository($base))->save(new ScanResult(
        startedAt: 1_770_000_000,
        finishedAt: 1_770_000_100,
        host: 'test',
        mode: 'local',
        sites: $sites,
        inventory: $inventaire,
        analysis: (new Linker(180))->analyse($sites, $inventaire),
    ));

    $noyau = new Kernel($base, dirname(__DIR__, 2) . '/templates', demo: true);

    foreach (['/', '/databases', '/orphans', '/database'] as $route) {
        $corps = $noyau->handle($route, $route === '/database' ? ['name' => 'u1_t22AF'] : [])->body;

        assertFalse(
            str_contains($corps, 'les plus sûres à supprimer'),
            "{$route} ne doit designer aucune base comme sure a supprimer sans l'avoir ouverte"
        );
        assertFalse(
            str_contains($corps, '>vide<'),
            "{$route} ne doit pas etiqueter « vide » une base jamais ouverte"
        );
    }

    // Le doute est dit, pas seulement tu — et il porte sur l'existence meme,
    // pas seulement sur la taille : une liste peut nommer une base supprimee.
    assertContains('non vérifiée', $noyau->handle('/orphans', [])->body);
    assertContains('?', $noyau->handle('/databases', [])->body);
    assertContains('existence même n’est pas établie', $noyau->handle('/database', ['name' => 'u1_t22AF'])->body);

    // Et le tableau de bord ne dit pas « aucune base inutilisee » sous un
    // compte d'orphelines non nul.
    $accueil = $noyau->handle('/', [])->body;
    assertFalse(str_contains($accueil, 'aucune base inutilisée'));
    assertContains('taille inconnue', $accueil);

    foreach ([$chemin, $chemin . '-wal', $chemin . '-shm'] as $fichier) {
        @unlink($fichier);
    }
});

register_shutdown_function(static function () use ($account, $path): void {
    $account->remove();

    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        @unlink($file);
    }
});
