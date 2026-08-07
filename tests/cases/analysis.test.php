<?php

declare(strict_types=1);

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Model\FindingKind;
use HostingerSpace\Model\LinkState;
use HostingerSpace\Model\Severity;
use HostingerSpace\Model\Site;
use HostingerSpace\Mysql\DatabaseInfo;
use HostingerSpace\Mysql\DatabaseInventory;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Demo\FakeAccount;
use HostingerSpace\Transport\LocalTransport;

$account = FakeAccount::create();
$transport = new LocalTransport($account->root);

$config = Config::fromArray([
    'mode' => 'local',
    'paths' => [
        'home' => $account->root,
        'domains_dir' => $account->root . '/domains',
        'ignore' => ['node_modules', 'vendor', 'cache'],
    ],
    'analysis' => ['max_depth' => 3, 'abandoned_after_days' => 180],
]);

/** @return array<string,DatabaseInfo> */
$buildInventory = static function (bool $complete = true): DatabaseInventory {
    $databases = [];

    foreach (FakeAccount::databases() as $name => $meta) {
        $info = new DatabaseInfo($name);
        $info->tableCount = $meta['tables'];
        $info->sizeBytes = $meta['size'];
        $info->updatedAt = $meta['updated'] === null ? null : strtotime($meta['updated']);
        $databases[$name] = $info;
    }

    return new DatabaseInventory($databases, [], $complete);
};

$sites = (new SiteScanner($transport, $config))->scan();
$byKey = [];

foreach ($sites as $site) {
    $byKey[$site->key] = $site;
}

$inventory = $buildInventory();
$analysis = (new Linker(180))->analyse($sites, $inventory);

$findingsOfKind = static fn (FindingKind $kind): array => array_values(array_filter(
    $analysis->findings,
    static fn ($f): bool => $f->kind === $kind
));

test('Tous les sites du compte sont trouves', function () use ($sites, $byKey): void {
    // Dix racines de domaine plus le domaine principal sur public_html.
    assertCount(11, $sites);

    foreach ([
        'domains/boulangerie-martin.fr/public_html',
        'domains/photos-durand.com/public_html',
        'domains/api-interne.dev/public_html',
        'domains/annuaire-2011.net/public_html',
        'domains/boutique-velo.fr/public_html',
        'domains/association-loire.org/public_html',
        'domains/cv-mehdi.fr/public_html',
        'domains/projet-abandonne.com/public_html',
        'domains/reserve-2024.fr/public_html',
        'domains/outils.mehdi.fr/public_html',
        'public_html',
    ] as $key) {
        assertTrue(isset($byKey[$key]), "site manquant : {$key}");
    }
});

test('Les bases orphelines sont exactement celles attendues', function () use ($analysis): void {
    // Ce sont les trois bases qu'aucun fichier de configuration ne mentionne.
    assertSame(
        ['u998877_ancienne', 'u998877_test', 'u998877_wordpress_old'],
        $analysis->orphans
    );
});

test("L'ancienne base laissee en commentaire compte bien comme orpheline", function () use ($analysis): void {
    // u998877_ancienne n'apparait que dans une ligne commentee du wp-config.
    // Un analyseur naif la croirait utilisee et la laisserait dormir.
    assertTrue(in_array('u998877_ancienne', $analysis->orphans, true));
});

test('Une base declaree mais absente du serveur est signalee', function () use ($findingsOfKind): void {
    $findings = $findingsOfKind(FindingKind::MissingDatabase);

    assertCount(1, $findings);
    assertContains('api-interne.dev', $findings[0]->title);
    assertSame(Severity::Critical, $findings[0]->severity);
});

test('Chaque site avec base est correctement rattache', function () use ($analysis): void {
    $expected = [
        'domains/boulangerie-martin.fr/public_html' => 'u998877_boulangerie',
        'domains/photos-durand.com/public_html' => 'u998877_photos',
        'domains/boutique-velo.fr/public_html' => 'u998877_velo',
        'domains/association-loire.org/public_html' => 'u998877_asso',
        'domains/annuaire-2011.net/public_html' => 'u998877_annuaire',
        'public_html' => 'u998877_principal',
    ];

    $actual = [];

    foreach ($analysis->links as $link) {
        if ($link->state === LinkState::Linked) {
            $actual[$link->siteKey] = $link->databaseName;
        }
    }

    ksort($expected);
    ksort($actual);
    assertSame($expected, $actual);
});

test('Une base vide et orpheline est signalee sans alarmisme', function () use ($findingsOfKind): void {
    $orphans = $findingsOfKind(FindingKind::OrphanDatabase);
    $empty = array_values(array_filter($orphans, static fn ($f): bool => $f->subject === 'u998877_test'));

    assertCount(1, $empty);
    assertSame(Severity::Info, $empty[0]->severity, 'une base vide ne merite pas une alerte forte');
    assertContains('totalement vide', $empty[0]->detail);
});

test('Un constat d orpheline propose une sauvegarde avant toute suppression', function () use ($findingsOfKind): void {
    $findings = $findingsOfKind(FindingKind::OrphanDatabase);
    $actions = implode(' ', $findings[0]->actions);

    assertContains('mysqldump', $actions);
    assertContains('grep -rl', $actions, 'il faut inviter a chercher le nom ailleurs avant de supprimer');
});

test("Une couverture MySQL partielle abaisse la certitude des orphelines", function () use ($sites, $buildInventory): void {
    // Point important : sans vue complete du serveur, une base peut sembler
    // orpheline alors qu'un site invisible l'utilise. Le constat doit alors
    // etre presente comme une piste, pas comme un fait.
    $partial = (new Linker(180))->analyse($sites, $buildInventory(complete: false));

    $orphans = array_values(array_filter(
        $partial->findings,
        static fn ($f): bool => $f->kind === FindingKind::OrphanDatabase && $f->subject === 'u998877_wordpress_old'
    ));

    assertCount(1, $orphans);
    assertSame(Severity::Info, $orphans[0]->severity);
    assertContains('pas une certitude', $orphans[0]->detail);
});

test("Une couverture partielle empeche de declarer un site casse", function () use ($sites, $buildInventory): void {
    $partial = (new Linker(180))->analyse($sites, $buildInventory(complete: false));

    $missing = array_values(array_filter(
        $partial->findings,
        static fn ($f): bool => $f->kind === FindingKind::MissingDatabase
    ));

    assertCount(0, $missing, "on ne declare pas un site casse quand on ne voit pas toutes les bases");

    $unverifiable = array_values(array_filter(
        $partial->links,
        static fn ($l): bool => $l->state === LinkState::Unverifiable
    ));

    assertCount(1, $unverifiable);
    assertSame('u998877_api', $unverifiable[0]->databaseName);
});

test('Un inventaire partiel est signale a l utilisateur', function () use ($sites, $buildInventory): void {
    $partial = (new Linker(180))->analyse($sites, $buildInventory(complete: false));

    $notes = array_values(array_filter(
        $partial->findings,
        static fn ($f): bool => $f->kind === FindingKind::PartialCoverage
    ));

    assertCount(1, $notes);
    assertContains('mysql.admin_user', implode(' ', $notes[0]->actions));
});

test('Un Adminer expose remonte en critique', function () use ($findingsOfKind): void {
    $findings = $findingsOfKind(FindingKind::ExposedAdminTool);

    assertCount(1, $findings);
    assertSame(Severity::Critical, $findings[0]->severity);
});

test('Les versions en fin de vie sont signalees', function () use ($findingsOfKind): void {
    $subjects = array_map(static fn ($f): string => $f->subject, $findingsOfKind(FindingKind::OutdatedApp));

    sort($subjects);
    assertSame([
        'domains/association-loire.org/public_html',   // Joomla 3.9
        'domains/boutique-velo.fr/public_html',        // PrestaShop 1.6
        'domains/photos-durand.com/public_html',       // WordPress 4.9
    ], $subjects);
});

test('Un WordPress a jour ne declenche pas d alerte de version', function () use ($findingsOfKind): void {
    foreach ($findingsOfKind(FindingKind::OutdatedApp) as $finding) {
        assertFalse(
            str_contains($finding->subject, 'boulangerie'),
            'WordPress 6.5.2 ne doit pas etre signale comme obsolete'
        );
    }
});

test('Le mode debogage en production est signale', function () use ($findingsOfKind): void {
    $findings = $findingsOfKind(FindingKind::DebugEnabled);

    assertCount(1, $findings);
    assertContains('api-interne.dev', $findings[0]->title);
});

test('Un dossier vide est signale sans etre confondu avec un site casse', function () use ($findingsOfKind): void {
    $findings = $findingsOfKind(FindingKind::EmptyDirectory);

    assertCount(1, $findings);
    assertContains('reserve-2024.fr', $findings[0]->title);
    assertSame(Severity::Info, $findings[0]->severity);
});

test('Les sites statiques ne reclament pas de base', function () use ($findingsOfKind): void {
    foreach ($findingsOfKind(FindingKind::SiteWithoutDatabase) as $finding) {
        assertFalse(str_contains($finding->subject, 'cv-mehdi'), 'un site statique n a pas besoin de base');
        assertFalse(str_contains($finding->subject, 'projet-abandonne'), 'une page de parcage non plus');
    }
});

test('Les tailles sont rendues lisibles', function (): void {
    assertSame('0 o', Linker::humanBytes(0));
    assertSame('512 o', Linker::humanBytes(512));
    assertSame('1,0 ko', Linker::humanBytes(1024));
    // Au-dela de 10, la decimale n'apporte rien : « 18 Mo » se lit mieux.
    assertSame('18 Mo', Linker::humanBytes(18_874_368));
    assertSame('92 Mo', Linker::humanBytes(96_468_992));
});

register_shutdown_function(static fn () => $account->remove());
