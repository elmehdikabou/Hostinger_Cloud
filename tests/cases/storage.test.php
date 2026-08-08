<?php

declare(strict_types=1);

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Mysql\DatabaseInfo;
use HostingerSpace\Mysql\DatabaseInventory;
use HostingerSpace\Scanner\ScanResult;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Storage\Database;
use HostingerSpace\Storage\ScanComparer;
use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Demo\FakeAccount;
use HostingerSpace\Transport\LocalTransport;

$account = FakeAccount::create();
$transport = new LocalTransport($account->root);
$config = Config::fromArray([
    'mode' => 'local',
    'paths' => ['home' => $account->root, 'domains_dir' => $account->root . '/domains', 'ignore' => []],
    'analysis' => ['max_depth' => 3],
]);

$sites = (new SiteScanner($transport, $config))->scan();

/** @param array<int,string> $exclude */
$makeInventory = static function (array $exclude = [], array $extra = []): DatabaseInventory {
    $databases = [];

    foreach (FakeAccount::databases() as $name => $meta) {
        if (in_array($name, $exclude, true)) {
            continue;
        }

        $info = new DatabaseInfo($name);
        $info->tableCount = $meta['tables'];
        $info->sizeBytes = $meta['size'];
        $databases[$name] = $info;
    }

    foreach ($extra as $name => $size) {
        $info = new DatabaseInfo($name);
        $info->tableCount = 5;
        $info->sizeBytes = $size;
        $databases[$name] = $info;
    }

    ksort($databases);

    return new DatabaseInventory($databases, [], true);
};

$makeResult = static function (DatabaseInventory $inventory, array $sites) use ($config): ScanResult {
    return new ScanResult(
        startedAt: 1_770_000_000,
        finishedAt: 1_770_000_042,
        host: 'ssh://u998877@145.14.0.1:65002',
        mode: 'ssh',
        sites: $sites,
        inventory: $inventory,
        analysis: (new Linker(180))->analyse($sites, $inventory),
    );
};

$path = sys_get_temp_dir() . '/hspace-test-' . bin2hex(random_bytes(6)) . '.sqlite';
$database = new Database($path);
$repository = new ScanRepository($database);

$firstScanId = $repository->save($makeResult($makeInventory(), $sites));

test('Un scan est enregistre avec son resume', function () use ($repository, $firstScanId): void {
    $scan = $repository->scan($firstScanId);

    assertSame(11, (int) $scan['site_count']);
    assertSame(9, (int) $scan['database_count']);
    assertSame(3, (int) $scan['orphan_count']);
    assertSame('ssh', $scan['mode']);
    assertSame(1, (int) $scan['coverage_complete']);
});

test('Les sites sont relus avec leurs metadonnees', function () use ($repository, $firstScanId): void {
    $site = $repository->site($firstScanId, 'domains/boulangerie-martin.fr/public_html');

    assertSame('wordpress', $site['app']);
    assertSame('6.5.2', $site['version']);
    assertSame('boulangerie-martin.fr', $site['domain']);
});

test('Les orphelines sont relues, triees par taille', function () use ($repository, $firstScanId): void {
    $names = array_map(static fn (array $r): string => $r['name'], $repository->orphans($firstScanId));

    assertSame(['u998877_ancienne', 'u998877_wordpress_old', 'u998877_test'], $names);
});

test('Aucun mot de passe n est stocke', function () use ($database, $account): void {
    // Garde-fou volontaire : le compte fictif contient des mots de passe
    // reconnaissables. S'ils apparaissent un jour dans la base, c'est qu'une
    // regression a fait de l'outil un second coffre a identifiants.
    $dump = '';

    foreach (['sites', 'databases', 'links', 'findings', 'scans'] as $table) {
        foreach ($database->all("SELECT * FROM {$table}") as $row) {
            $dump .= implode('|', array_map(strval(...), array_map(
                static fn (mixed $v): string => $v === null ? '' : (string) $v,
                $row
            )));
        }
    }

    foreach (['e5t#Un\'MotDePasse', 'photo2019', 'velo123', 'assoc2018', 'vieuxmdp', 'mot de passe # avec diese'] as $secret) {
        assertFalse(str_contains($dump, $secret), "le mot de passe « {$secret} » ne doit pas etre stocke");
    }
});

test('Le nom d utilisateur MySQL reste disponible pour le diagnostic', function () use ($repository, $firstScanId): void {
    // Sans mot de passe, mais avec l'utilisateur : c'est ce qu'il faut pour
    // retrouver la bonne ligne dans hPanel.
    $links = $repository->linksForSite($firstScanId, 'domains/boulangerie-martin.fr/public_html');

    assertCount(1, $links);
    assertSame('u998877_boul', $links[0]['db_user']);
    assertSame('wp_', $links[0]['table_prefix']);
});

test('Les constats sont tries par severite', function () use ($repository, $firstScanId): void {
    $severities = array_map(static fn (array $r): string => $r['severity'], $repository->findings($firstScanId));

    $sorted = $severities;
    usort($sorted, static fn (string $a, string $b): int => ['critical' => 0, 'warning' => 1, 'info' => 2][$a]
        <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b]);

    assertSame($sorted, $severities);
    assertSame('critical', $severities[0]);
});

test('Les liens d une base remontent les sites qui l utilisent', function () use ($repository, $firstScanId): void {
    $links = $repository->linksForDatabase($firstScanId, 'u998877_velo');

    assertCount(1, $links);
    assertSame('boutique-velo.fr', $links[0]['domain']);
    assertSame('PrestaShop', $links[0]['app_label']);
});

// --- Deuxieme scan : une base a disparu, une autre est apparue --------------
$secondScanId = $repository->save($makeResult(
    $makeInventory(exclude: ['u998877_ancienne'], extra: ['u998877_nouvelle' => 2_097_152]),
    $sites
));

test('La comparaison de deux scans montre ce qui a bouge', function () use ($database, $firstScanId, $secondScanId): void {
    $diff = (new ScanComparer($database))->compare($firstScanId, $secondScanId);

    assertSame(['u998877_nouvelle'], $diff->databasesAdded);
    assertSame(['u998877_ancienne'], $diff->databasesRemoved);
    assertSame(['u998877_nouvelle'], $diff->orphansAppeared, 'la nouvelle base n est utilisee par aucun site');
    assertSame(['u998877_ancienne'], $diff->orphansResolved, 'supprimee, elle n est plus orpheline');
    assertCount(0, $diff->sitesAdded);
    assertCount(0, $diff->sitesRemoved);
});

test('Un constat disparu est distingue d un constat reformule', function () use ($database, $firstScanId, $secondScanId): void {
    $diff = (new ScanComparer($database))->compare($firstScanId, $secondScanId);
    $resolved = array_map(static fn (array $f): string => $f['title'], $diff->findingsResolved);

    assertCount(1, $resolved);
    assertContains('u998877_ancienne', $resolved[0]);
});

test('Comparer un scan avec lui-meme ne montre aucun changement', function () use ($database, $firstScanId): void {
    assertTrue((new ScanComparer($database))->compare($firstScanId, $firstScanId)->isEmpty());
});

test("L'historique est borne pour ne pas grossir indefiniment", function () use ($repository): void {
    assertSame(1, $repository->prune(keep: 1));
    assertCount(1, $repository->scans());
});

test('Une base de releves anterieure gagne la colonne « mesuree »', function (): void {
    /*
     * L'utilisateur a deja des releves en place : la colonne doit arriver par
     * migration, sans repartir de zero. Et les lignes deja ecrites viennent
     * toutes d'un acces MySQL reel — elles sont donc mesurees, sous peine de
     * voir un espace connu se couvrir de « ? » du jour au lendemain.
     */
    $chemin = sys_get_temp_dir() . '/hspace-migr-' . bin2hex(random_bytes(6)) . '.sqlite';

    // Un fichier au schema d'origine, sans la colonne, avec une ligne dedans.
    $pdo = new PDO('sqlite:' . $chemin);
    $pdo->exec('CREATE TABLE databases (id INTEGER PRIMARY KEY, name TEXT NOT NULL, size_bytes INTEGER)');
    $pdo->exec("INSERT INTO databases (name, size_bytes) VALUES ('u1_ancienne', 4096)");
    $pdo->exec('PRAGMA user_version = 1');
    $pdo = null;

    new Database($chemin);

    $relu = new PDO('sqlite:' . $chemin);
    assertSame(2, (int) $relu->query('PRAGMA user_version')->fetchColumn());
    assertSame(1, (int) $relu->query("SELECT measured FROM databases WHERE name = 'u1_ancienne'")->fetchColumn());
    assertSame(4096, (int) $relu->query("SELECT size_bytes FROM databases WHERE name = 'u1_ancienne'")->fetchColumn());
    $relu = null;

    // Rouvrir ne rejoue pas la migration — l'ALTER echouerait en doublon.
    new Database($chemin);

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
