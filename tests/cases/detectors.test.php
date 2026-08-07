<?php

declare(strict_types=1);

use HostingerSpace\Detector\DetectorRegistry;
use HostingerSpace\Detector\SiteContext;
use HostingerSpace\Tests\FakeAccount;
use HostingerSpace\Transport\LocalTransport;

$account = FakeAccount::create();
$transport = new LocalTransport();
$registry = new DetectorRegistry();

$detect = static function (string $relative) use ($account, $transport, $registry) {
    return $registry->detect(new SiteContext($transport, $account->root . '/' . $relative, $relative));
};

test('WordPress est identifie avec sa version et sa base', function () use ($detect): void {
    $detection = $detect('domains/boulangerie-martin.fr/public_html');

    assertSame('wordpress', $detection->app);
    assertSame('6.5.2', $detection->version);
    assertCount(1, $detection->databases);
    assertSame('u998877_boulangerie', $detection->databases[0]->database);
    assertSame('u998877_boul', $detection->databases[0]->user);
    assertSame("e5t#Un'MotDePasse", $detection->databases[0]->password);
    assertSame('wp_', $detection->databases[0]->tablePrefix);
});

test("WordPress ignore l'ancienne base laissee en commentaire", function () use ($detect): void {
    $detection = $detect('domains/boulangerie-martin.fr/public_html');

    assertSame('u998877_boulangerie', $detection->databases[0]->database);
});

test('Un wp-config.php place au-dessus de la racine web est trouve', function () use ($detect): void {
    // Pratique courante pour sortir les identifiants de l'espace public :
    // sans ce rattrapage, le site paraitrait sans base et sa base paraitrait
    // orpheline. Deux faux constats d'un coup.
    $detection = $detect('domains/photos-durand.com/public_html');

    assertSame('wordpress', $detection->app);
    assertSame('4.9.8', $detection->version);
    assertCount(1, $detection->databases);
    assertSame('u998877_photos', $detection->databases[0]->database);
});

test('Laravel est identifie depuis public_html avec le projet au-dessus', function () use ($detect): void {
    $detection = $detect('domains/api-interne.dev/public_html');

    assertSame('laravel', $detection->app);
    assertSame('10.48.4', $detection->version);
    assertSame('u998877_api', $detection->databases[0]->database);
    assertSame('mot de passe # avec diese', $detection->databases[0]->password);
    assertContains('APP_DEBUG', implode(' ', $detection->notes));
});

test('PrestaShop 1.6 est identifie', function () use ($detect): void {
    $detection = $detect('domains/boutique-velo.fr/public_html');

    assertSame('prestashop', $detection->app);
    assertSame('1.6.1.24', $detection->version);
    assertSame('u998877_velo', $detection->databases[0]->database);
    assertSame('ps_', $detection->databases[0]->tablePrefix);
});

test('Joomla 3 est identifie', function () use ($detect): void {
    $detection = $detect('domains/association-loire.org/public_html');

    assertSame('joomla', $detection->app);
    assertSame('3.9.24', $detection->version);
    assertSame('u998877_asso', $detection->databases[0]->database);
    assertSame('jos_', $detection->databases[0]->tablePrefix);
});

test('Un vieux site PHP est rattache via mysqli_connect', function () use ($detect): void {
    $detection = $detect('domains/annuaire-2011.net/public_html');

    assertSame('php', $detection->app);
    assertSame('u998877_annuaire', $detection->databases[0]->database);
    assertSame('u998877_annu', $detection->databases[0]->user);
});

test('Un site statique est reconnu sans base', function () use ($detect): void {
    $detection = $detect('domains/cv-mehdi.fr/public_html');

    assertSame('static', $detection->app);
    assertCount(0, $detection->databases);
});

test("Une page d'attente est distinguee d'un vrai site", function () use ($detect): void {
    assertSame('placeholder', $detect('domains/projet-abandonne.com/public_html')->app);
});

test('Un dossier vide est signale comme tel', function () use ($detect): void {
    assertSame('empty', $detect('domains/reserve-2024.fr/public_html')->app);
});

test('Un Adminer oublie est signale', function () use ($detect): void {
    $detection = $detect('domains/outils.mehdi.fr/public_html');

    assertSame('adminer', $detection->app);
    assertContains('acces direct', implode(' ', $detection->notes));
});

test('Le domaine principal sur public_html est rattache', function () use ($detect): void {
    $detection = $detect('public_html');

    assertSame('php', $detection->app);
    assertSame('u998877_principal', $detection->databases[0]->database);
});

test('Une base sur un hote externe est reconnue comme non locale', function () use ($account, $transport, $registry): void {
    // Un site branche sur une base externe ne doit pas faire croire a une
    // base manquante cote Hostinger.
    $root = $account->root . '/domains/externe.fr/public_html';
    mkdir($root . '/wp-includes', 0o775, true);
    file_put_contents($root . '/wp-config.php', <<<'PHP'
        <?php
        define('DB_NAME', 'production');
        define('DB_USER', 'app');
        define('DB_PASSWORD', 'x');
        define('DB_HOST', 'db.rds.amazonaws.com');
        PHP);

    $detection = $registry->detect(new SiteContext($transport, $root, 'externe.fr'));

    assertSame('wordpress', $detection->app);
    assertFalse($detection->databases[0]->isLocal());
    assertTrue($detection->databases[0]->named());
});

register_shutdown_function(static fn () => $account->remove());
