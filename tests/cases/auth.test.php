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
use HostingerSpace\Web\Auth;
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

$path = sys_get_temp_dir() . '/hspace-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
$database = new Database($path);

(new ScanRepository($database))->save(new ScanResult(
    startedAt: 1_770_000_000,
    finishedAt: 1_770_000_100,
    host: 'test',
    mode: 'local',
    sites: $sites,
    inventory: $inventory,
    analysis: (new Linker(180))->analyse($sites, $inventory),
));

$templates = dirname(__DIR__, 2) . '/templates';
$hash = Auth::hash('unMotDePasseSolide');

$kernel = static fn (Auth $auth): Kernel => new Kernel($database, $templates, false, $auth);

test('Depuis la machine locale, aucun mot de passe n est demande', function () use ($kernel): void {
    // « hspace serve » chez soi s'adresse deja a son proprietaire.
    $response = $kernel(new Auth(null, localClient: true))->handle('/', []);

    assertSame(200, $response->status);
    assertContains('Tableau de bord', $response->body);
});

test("Depuis l'exterieur sans mot de passe configure, rien n'est affiche", function () use ($kernel): void {
    // Le point le plus important du controle d'acces : on refuse de montrer
    // les donnees « en attendant » que la protection soit mise en place.
    $response = $kernel(new Auth(null, localClient: false))->handle('/', []);

    assertSame(403, $response->status);
    assertContains("n'est pas protégée", $response->body);
    assertContains('hspace passwd', $response->body);
});

test("Aucune donnee d'inventaire ne filtre avant identification", function () use ($kernel, $hash): void {
    $pages = ['/', '/sites', '/databases', '/orphans', '/findings', '/domains', '/scans'];

    foreach ([new Auth(null, localClient: false), new Auth($hash, localClient: false)] as $auth) {
        foreach ($pages as $page) {
            $body = $kernel($auth)->handle($page, [])->body;

            foreach (['u998877_boulangerie', 'boutique-velo.fr', 'Adminer', 'u998877_boul'] as $secret) {
                assertFalse(
                    str_contains($body, $secret),
                    "« {$secret} » ne doit pas apparaitre sur {$page} avant identification"
                );
            }
        }
    }
});

test('Un mot de passe configure fait apparaitre le formulaire', function () use ($kernel, $hash): void {
    $response = $kernel(new Auth($hash, localClient: false))->handle('/', []);

    assertSame(200, $response->status);
    assertContains('Se connecter', $response->body);
    assertContains('name="motdepasse"', $response->body);
});

test('Le condense verifie le bon mot de passe et rejette les autres', function () use ($hash): void {
    $auth = new Auth($hash, localClient: false);

    assertTrue(password_verify('unMotDePasseSolide', $hash));
    assertFalse(password_verify('unMotDePasseSolid', $hash));
    assertTrue($auth->configured());
    assertFalse($auth->needsSetup());
});

test('Le mot de passe en clair n apparait jamais dans le condense', function () use ($hash): void {
    assertFalse(str_contains($hash, 'unMotDePasseSolide'));
    assertTrue(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'));
});

test('Deux condenses du meme mot de passe different', function (): void {
    // Le sel est tire au hasard : deux installations avec le meme mot de
    // passe n'ont pas la meme empreinte dans leur configuration.
    assertFalse(Auth::hash('identique') === Auth::hash('identique'));
});

test("L'origine locale est reconnue sur IPv4 et IPv6", function (): void {
    assertTrue(Auth::isLocalClient(['REMOTE_ADDR' => '127.0.0.1']));
    assertTrue(Auth::isLocalClient(['REMOTE_ADDR' => '::1']));
    assertFalse(Auth::isLocalClient(['REMOTE_ADDR' => '203.0.113.9']));
    assertFalse(Auth::isLocalClient(['REMOTE_ADDR' => '127.0.0.1.evil.com']));
    assertFalse(Auth::isLocalClient([]), "sans adresse connue, on ne suppose pas l'origine locale");
});

test('Une redirection ne peut pas envoyer ailleurs', function (): void {
    // Une adresse absolue fournie de l'exterieur ne doit pas se transformer
    // en redirection vers un autre site.
    $response = HostingerSpace\Web\Response::redirect('https://exemple-malveillant.fr/piege');

    assertSame('/piege', $response->headers['Location']);
    assertSame(302, $response->status);
});

register_shutdown_function(static function () use ($account, $path): void {
    $account->remove();

    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        @unlink($file);
    }
});

// --- Mot de passe engendre, pour les serveurs sans stty --------------------

test("Un mot de passe engendre protege reellement l'interface", function (): void {
    // Regression : Hostinger desactive shell_exec par disable_functions, et
    // le masquage de saisie s'appuyait dessus sans verifier qu'il existe.
    // La commande tombait sur une erreur fatale au milieu du deploiement.
    $directory = sys_get_temp_dir() . '/hspace-pw-' . bin2hex(random_bytes(6));
    mkdir($directory, 0o775, true);
    $target = $directory . '/config.php';
    copy(dirname(__DIR__, 2) . '/config/config.example.php', $target);

    $stream = fopen('php://memory', 'w+');
    $code = (new HostingerSpace\Console\Command\PasswordCommand())->run(
        ['--generate'],
        new HostingerSpace\Console\Output($stream),
        new HostingerSpace\Console\Context($target),
    );

    rewind($stream);
    $shown = (string) stream_get_contents($stream);
    fclose($stream);

    assertSame(0, $code);

    // Le mot de passe est affiche une fois, sur sa propre ligne.
    assertTrue(
        preg_match('/^\s+([A-Za-z0-9]{24})\s*$/m', $shown, $matches) === 1,
        'le mot de passe engendre doit etre affiche'
    );

    $password = $matches[1];
    $config = require $target;

    assertTrue(password_verify($password, $config['web']['password_hash']));
    assertFalse(password_verify($password . 'x', $config['web']['password_hash']));
    assertFalse(str_contains($config['web']['password_hash'], $password), 'seul le condense est ecrit');

    // Les caracteres qu'on confond en recopiant a l'oeil sont exclus : ce
    // mot de passe sera lu a l'ecran puis colle ailleurs.
    foreach (['0', 'O', '1', 'l', 'I'] as $ambigu) {
        assertFalse(str_contains($password, $ambigu), "« {$ambigu} » se confond a la lecture");
    }

    unlink($target);
    rmdir($directory);
});
