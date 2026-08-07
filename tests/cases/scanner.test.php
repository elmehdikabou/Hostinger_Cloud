<?php

declare(strict_types=1);

use HostingerSpace\Config;
use HostingerSpace\Demo\FakeAccount;
use HostingerSpace\Scanner\FilesystemStats;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Transport\LocalTransport;

$account = FakeAccount::create();
$transport = new LocalTransport($account->root);
$config = Config::fromArray([
    'mode' => 'local',
    'paths' => ['home' => $account->root, 'domains_dir' => $account->root . '/domains', 'ignore' => ['cache', 'vendor']],
    'analysis' => ['max_depth' => 3],
]);

test('Les tailles et le nombre de fichiers correspondent au disque', function () use ($account, $transport): void {
    $path = $account->root . '/domains/boulangerie-martin.fr/public_html';
    $stats = (new FilesystemStats($transport))->measure([$path]);

    $expectedSize = 0;
    $expectedCount = 0;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $expectedSize += $file->getSize();
            $expectedCount++;
        }
    }

    assertSame($expectedSize, $stats[$path]['size']);
    assertSame($expectedCount, $stats[$path]['files']);
    assertTrue($stats[$path]['mtime'] > 0, 'la date du fichier le plus recent doit etre connue');
});

test('Les dossiers exclus ne comptent pas dans la mesure', function () use ($account, $transport): void {
    // Un cache regenere chaque nuit ferait passer pour vivant un site fige :
    // il ne doit peser ni dans la taille ni dans la date d'activite.
    $path = $account->root . '/domains/cv-mehdi.fr/public_html';
    $before = (new FilesystemStats($transport, ['cache']))->measure([$path])[$path];

    mkdir($path . '/cache', 0o775, true);
    file_put_contents($path . '/cache/gros.tmp', str_repeat('x', 50_000));

    $after = (new FilesystemStats($transport, ['cache']))->measure([$path])[$path];
    $withoutExclusion = (new FilesystemStats($transport))->measure([$path])[$path];

    assertSame($before['size'], $after['size'], 'le cache ne doit pas peser dans la taille');
    assertSame($before['files'], $after['files']);
    assertTrue($withoutExclusion['size'] > $after['size'], 'sans exclusion, le cache compterait');
});

test('Un chemin inexistant est rendu a zero plutot que disparaitre', function () use ($transport): void {
    $stats = (new FilesystemStats($transport))->measure(['/chemin/qui/nexiste/pas']);

    assertCount(1, $stats);
    assertSame(0, $stats['/chemin/qui/nexiste/pas']['size']);
    assertNull($stats['/chemin/qui/nexiste/pas']['mtime']);
});

test('Un chemin contenant une apostrophe ne casse pas la commande', function () use ($account, $transport): void {
    // Les chemins partent dans un shell : sans echappement correct, une
    // apostrophe suffirait a casser la mesure — voire a executer autre chose.
    $path = $account->root . "/domains/l'agence.fr";
    $contents = '<?php // test';
    mkdir($path, 0o775, true);
    file_put_contents($path . '/index.php', $contents);

    $stats = (new FilesystemStats($transport))->measure([$path]);

    assertSame(strlen($contents), $stats[$path]['size']);
    assertSame(1, $stats[$path]['files']);
});

test('La fonction de suivi conserve le contexte de son appelant', function () use ($transport, $config): void {
    // Regression : Closure::call() rebindait $this sur le scanner, ce qui
    // faisait echouer toute fonction de suivi ayant son propre $this — le
    // scan entier s'arretait alors sur « Value of type null is not callable ».
    $collector = new class () {
        /** @var array<int,string> */
        public array $seen = [];

        public function record(string $name): void
        {
            $this->seen[] = $name;
        }
    };

    $sites = (new SiteScanner($transport, $config))->scan(function (string $name) use ($collector): void {
        $collector->record($name);
    });

    assertSame(count($sites), count($collector->seen));
    assertTrue(in_array('boulangerie-martin.fr', $collector->seen, true));
});

test('Un scan sans fonction de suivi fonctionne aussi', function () use ($transport, $config): void {
    assertTrue(count((new SiteScanner($transport, $config))->scan()) > 0);
});

register_shutdown_function(static fn () => $account->remove());
