<?php

declare(strict_types=1);

use HostingerSpace\Detector\Parse;

test('Une ligne define commentee est ignoree', function (): void {
    // Le piege classique : les wp-config.php gardent souvent l'ancienne base
    // en commentaire. Une regex la prendrait et rattacherait le site a la
    // mauvaise base — ou la vraie base passerait pour orpheline.
    $source = <<<'PHP'
        <?php
        // define('DB_NAME', 'ancienne_base');
        # define('DB_NAME', 'encore_plus_vieille');
        /* define('DB_NAME', 'bloc_commente'); */
        define('DB_NAME', 'la_bonne_base');
        PHP;

    assertSame('la_bonne_base', Parse::phpDefine($source, 'DB_NAME'));
});

test('Un mot de passe avec guillemet echappe est lu entierement', function (): void {
    $source = <<<'PHP'
        <?php
        define('DB_PASSWORD', 'e5t#Un\'MotDePasse');
        define('DB_PASSWORD_BIS', "avec\"guillemet");
        PHP;

    assertSame("e5t#Un'MotDePasse", Parse::phpDefine($source, 'DB_PASSWORD'));
    assertSame('avec"guillemet', Parse::phpDefine($source, 'DB_PASSWORD_BIS'));
});

test('La premiere definition l emporte, comme en PHP', function (): void {
    $source = "<?php\ndefine('DB_NAME', 'premiere');\ndefine('DB_NAME', 'seconde');";

    assertSame('premiere', Parse::phpDefine($source, 'DB_NAME'));
});

test('Les variables et proprietes scalaires sont extraites', function (): void {
    $source = <<<'PHP'
        <?php
        $table_prefix = 'wp_';
        class JConfig {
            public $db = 'u1_asso';
            public $dbprefix = 'jos_';
        }
        PHP;

    assertSame('wp_', Parse::phpVariable($source, 'table_prefix'));
    assertSame('u1_asso', Parse::phpVariable($source, 'db'));
    assertSame('jos_', Parse::phpVariable($source, 'dbprefix'));
});

test('Les entrees de tableau cle => valeur sont extraites', function (): void {
    $source = <<<'PHP'
        <?php
        $databases['default']['default'] = [
            'database' => 'u1_drupal',
            'username' => 'u1_user',
            'host' => 'localhost',
        ];
        PHP;

    assertSame('u1_drupal', Parse::phpArrayValue($source, 'database'));
    assertSame('u1_user', Parse::phpArrayValue($source, 'username'));
});

test('Les constantes de classe sont extraites', function (): void {
    $source = "<?php\nclass Version { const MAJOR_VERSION = 5; const RELEASE = '3.9'; }";

    assertSame('5', Parse::phpClassConstant($source, 'MAJOR_VERSION'));
    assertSame('3.9', Parse::phpClassConstant($source, 'RELEASE'));
});

test('Le .env gere guillemets, diese et export', function (): void {
    $source = <<<'ENV'
        # commentaire
        export APP_ENV=production
        DB_DATABASE=u1_api
        DB_PASSWORD="mot de passe # avec diese"
        DB_USERNAME='simple'
        DB_HOST=127.0.0.1 # commentaire de fin
        VIDE=
        ENV;

    $values = Parse::dotenv($source);

    assertSame('production', $values['APP_ENV']);
    assertSame('u1_api', $values['DB_DATABASE']);
    assertSame('mot de passe # avec diese', $values['DB_PASSWORD'], 'le diese entre guillemets fait partie du mot de passe');
    assertSame('simple', $values['DB_USERNAME']);
    assertSame('127.0.0.1', $values['DB_HOST'], 'le commentaire de fin de ligne doit etre retire');
    assertSame('', $values['VIDE']);
});

test('Une URL de base Doctrine est decomposee', function (): void {
    $parsed = Parse::databaseUrl('mysql://u1_user:m%40t%3Ade%2Fpasse@localhost:3307/u1_symfony?serverVersion=8.0');

    assertSame('u1_user', $parsed['user']);
    assertSame('m@t:de/passe', $parsed['password'], 'les caracteres encodes doivent etre decodes');
    assertSame('localhost', $parsed['host']);
    assertSame(3307, $parsed['port']);
    assertSame('u1_symfony', $parsed['database']);
});

test('Une URL non MySQL est rejetee', function (): void {
    assertNull(Parse::databaseUrl('postgresql://u:p@localhost/base'));
    assertNull(Parse::databaseUrl('pas-une-url'));
});

test('Un chemin de socket n est pas confondu avec un port', function (): void {
    // DB_HOST peut valoir « localhost:/var/run/mysqld/mysqld.sock ». Prendre
    // le chemin pour un port donnerait un port 0 et une connexion impossible.
    assertSame(['localhost', 3306], Parse::hostAndPort('localhost:/var/run/mysqld/mysqld.sock'));
    assertSame(['localhost', 3307], Parse::hostAndPort('localhost:3307'));
    assertSame(['db.exemple.fr', 3306], Parse::hostAndPort('db.exemple.fr'));
    assertSame(['localhost', 3306], Parse::hostAndPort(''));
});

test('Les arguments litteraux d un appel sont extraits', function (): void {
    $source = <<<'PHP'
        <?php
        $lien = mysqli_connect('localhost', 'u1_annu', 'vieuxmdp', 'u1_annuaire');
        $autre = mysqli_connect($host, $user, $pass, 'u1_deux');
        PHP;

    $calls = Parse::phpCallArguments($source, 'mysqli_connect');

    assertCount(2, $calls);
    assertSame(['localhost', 'u1_annu', 'vieuxmdp', 'u1_annuaire'], $calls[0]);
    assertNull($calls[1][0], 'un argument variable ne peut pas etre resolu');
    assertSame('u1_deux', $calls[1][3]);
});

test('Un DSN PDO est decompose', function (): void {
    $parts = Parse::pdoDsn('mysql:host=localhost;dbname=u1_base;charset=utf8mb4');

    assertSame('localhost', $parts['host']);
    assertSame('u1_base', $parts['dbname']);
});

test('La version d un paquet est lue dans composer.lock', function (): void {
    $lock = json_encode(['packages' => [
        ['name' => 'autre/paquet', 'version' => '1.0.0'],
        ['name' => 'laravel/framework', 'version' => 'v10.48.4'],
    ]]);

    assertSame('10.48.4', Parse::composerLockVersion($lock, 'laravel/framework'));
    assertNull(Parse::composerLockVersion($lock, 'absent/paquet'));
    assertNull(Parse::composerLockVersion('{json invalide', 'laravel/framework'));
});
