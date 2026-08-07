<?php

declare(strict_types=1);

namespace HostingerSpace\Demo;

use HostingerSpace\Mysql\CredentialProbe;
use HostingerSpace\Mysql\DatabaseInfo;
use HostingerSpace\Mysql\DatabaseInventory;

/**
 * Construit sur disque une arborescence ressemblant a un compte Hostinger.
 *
 * Sert aux tests, et alimente aussi la commande « hspace demo » : on peut
 * ainsi voir l'interface remplie de donnees credibles sans brancher un
 * hebergement reel.
 */
final class FakeAccount
{
    public function __construct(public readonly string $root)
    {
    }

    public static function create(?string $root = null): self
    {
        $root ??= sys_get_temp_dir() . '/hspace-fake-' . bin2hex(random_bytes(6));
        $account = new self($root);
        $account->build();

        return $account;
    }

    public function build(): void
    {
        $this->reset();

        // --- WordPress complet, a jour, avec base bien rattachee -------------
        $this->file('domains/boulangerie-martin.fr/public_html/wp-config.php', <<<'PHP'
            <?php
            // define('DB_NAME', 'u998877_ancienne');   ancienne base, migree en 2023
            define( 'DB_NAME', 'u998877_boulangerie' );
            define( 'DB_USER', 'u998877_boul' );
            define( 'DB_PASSWORD', 'e5t#Un\'MotDePasse' );
            define( 'DB_HOST', 'localhost' );
            $table_prefix = 'wp_';
            define('WP_DEBUG', false);
            PHP);
        $this->file('domains/boulangerie-martin.fr/public_html/wp-includes/version.php', <<<'PHP'
            <?php
            $wp_version = '6.5.2';
            PHP);
        $this->file('domains/boulangerie-martin.fr/public_html/index.php', "<?php require __DIR__ . '/wp-blog-header.php';");

        // --- WordPress ancien, wp-config au-dessus de la racine web ----------
        $this->file('domains/photos-durand.com/wp-config.php', <<<'PHP'
            <?php
            define('DB_NAME', 'u998877_photos');
            define('DB_USER', 'u998877_photos');
            define('DB_PASSWORD', 'photo2019');
            define('DB_HOST', 'localhost:3306');
            $table_prefix = 'wpph_';
            PHP);
        $this->file('domains/photos-durand.com/public_html/wp-includes/version.php', "<?php\n\$wp_version = '4.9.8';");
        $this->file('domains/photos-durand.com/public_html/wp-load.php', '<?php // amorçage');

        // --- Laravel servi depuis public/, base absente cote MySQL -----------
        $this->file('domains/api-interne.dev/artisan', '<?php // console');
        $this->file('domains/api-interne.dev/bootstrap/app.php', '<?php return new Application();');
        $this->file('domains/api-interne.dev/.env', <<<'ENV'
            APP_ENV=production
            APP_DEBUG=true
            DB_CONNECTION=mysql
            DB_HOST=127.0.0.1
            DB_PORT=3306
            DB_DATABASE=u998877_api
            DB_USERNAME=u998877_api
            DB_PASSWORD="mot de passe # avec diese"
            ENV);
        $this->file('domains/api-interne.dev/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v10.48.4']],
        ], JSON_PRETTY_PRINT));
        $this->file('domains/api-interne.dev/public_html/index.php', "<?php require __DIR__.'/../vendor/autoload.php';");

        // --- Vieux PHP a la main, base rattachee par mysqli_connect ----------
        $this->file('domains/annuaire-2011.net/public_html/index.php', '<?php include "inc/config.php";');
        $this->file('domains/annuaire-2011.net/public_html/inc/config.php', <<<'PHP'
            <?php
            $lien = mysqli_connect('localhost', 'u998877_annu', 'vieuxmdp', 'u998877_annuaire');
            PHP);

        // --- PrestaShop 1.6 --------------------------------------------------
        $this->file('domains/boutique-velo.fr/public_html/config/settings.inc.php', <<<'PHP'
            <?php
            define('_DB_SERVER_', 'localhost');
            define('_DB_NAME_', 'u998877_velo');
            define('_DB_USER_', 'u998877_velo');
            define('_DB_PASSWD_', 'velo123');
            define('_DB_PREFIX_', 'ps_');
            define('_PS_VERSION_', '1.6.1.24');
            PHP);
        $this->file('domains/boutique-velo.fr/public_html/index.php', '<?php // prestashop');

        // --- Joomla 3 --------------------------------------------------------
        $this->file('domains/association-loire.org/public_html/configuration.php', <<<'PHP'
            <?php
            class JConfig {
                public $dbtype = 'mysqli';
                public $host = 'localhost';
                public $user = 'u998877_asso';
                public $password = 'assoc2018';
                public $db = 'u998877_asso';
                public $dbprefix = 'jos_';
            }
            PHP);
        $this->file('domains/association-loire.org/public_html/libraries/cms/version/version.php', <<<'PHP'
            <?php
            class Version {
                const RELEASE = '3.9';
                const DEV_LEVEL = '24';
            }
            PHP);
        $this->file('domains/association-loire.org/public_html/administrator/index.php', '<?php // admin');

        // --- Site statique ---------------------------------------------------
        $this->file('domains/cv-mehdi.fr/public_html/index.html', '<!doctype html><title>CV</title>');
        $this->file('domains/cv-mehdi.fr/public_html/style.css', 'body{font-family:sans-serif}');

        // --- Page de parcage --------------------------------------------------
        $this->file('domains/projet-abandonne.com/public_html/index.html', '<!doctype html><h1>Bientot disponible</h1>');

        // --- Domaine reserve, jamais publie ----------------------------------
        $this->dir('domains/reserve-2024.fr/public_html');

        // --- Adminer oublie ---------------------------------------------------
        $this->file('domains/outils.mehdi.fr/public_html/adminer-4.8.1.php', '<?php // adminer');

        // --- Domaine principal sur public_html --------------------------------
        $this->file('public_html/index.php', '<?php echo "Accueil";');
        $this->file('public_html/config.php', <<<'PHP'
            <?php
            define('DB_HOST', 'localhost');
            define('DB_USER', 'u998877_princ');
            define('DB_PASSWORD', 'principal');
            define('DB_NAME', 'u998877_principal');
            PHP);
    }

    /**
     * Ce que « verrait » MySQL sur ce compte fictif.
     *
     * Contient volontairement des cas qui doivent ressortir a l'analyse :
     * deux bases qu'aucun site n'utilise, et une base referencee par un site
     * mais absente du serveur.
     *
     * @return array<string,array{tables:int,size:int,updated:?string}>
     */
    public static function databases(): array
    {
        return [
            'u998877_boulangerie' => ['tables' => 42, 'size' => 18_874_368, 'updated' => '2026-08-01 09:12:00'],
            'u998877_photos' => ['tables' => 38, 'size' => 6_291_456, 'updated' => '2019-11-03 22:41:00'],
            'u998877_velo' => ['tables' => 312, 'size' => 96_468_992, 'updated' => '2026-07-28 17:05:00'],
            'u998877_asso' => ['tables' => 71, 'size' => 12_582_912, 'updated' => '2021-02-14 08:30:00'],
            'u998877_annuaire' => ['tables' => 4, 'size' => 524_288, 'updated' => '2013-06-22 14:00:00'],
            'u998877_principal' => ['tables' => 9, 'size' => 1_048_576, 'updated' => '2026-08-05 11:20:00'],
            // Orphelines : plus aucun site ne les reference.
            'u998877_ancienne' => ['tables' => 40, 'size' => 15_728_640, 'updated' => '2023-04-02 10:00:00'],
            'u998877_test' => ['tables' => 0, 'size' => 0, 'updated' => null],
            'u998877_wordpress_old' => ['tables' => 12, 'size' => 3_145_728, 'updated' => '2020-01-15 12:00:00'],
        ];
        // Note : u998877_api est reference par le Laravel mais absent d'ici,
        // ce qui doit produire un constat « base manquante ».
    }

    /**
     * L'inventaire MySQL correspondant, tel que le renverrait un vrai serveur.
     */
    public static function inventory(): DatabaseInventory
    {
        $databases = [];

        foreach (self::databases() as $name => $meta) {
            $info = new DatabaseInfo($name);
            $info->tableCount = $meta['tables'];
            $info->sizeBytes = $meta['size'];
            $info->rowEstimate = $meta['tables'] * 420;
            $info->charset = 'utf8mb4';
            $info->collation = 'utf8mb4_unicode_ci';
            $info->updatedAt = $meta['updated'] === null ? null : (strtotime($meta['updated']) ?: null);
            $info->addSource('u998877_demo@localhost (acces global)');

            $databases[$name] = $info;
        }

        return new DatabaseInventory(
            $databases,
            [new CredentialProbe('u998877_demo@localhost (acces global)', 'config', true, count($databases), null, true)],
            true,
        );
    }

    private function file(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $this->dir(dirname($relative));
        file_put_contents($path, $contents);
    }

    private function dir(string $relative): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir($path)) {
            mkdir($path, 0o775, true);
        }
    }

    public function reset(): void
    {
        $this->remove($this->root);
        mkdir($this->root, 0o775, true);
    }

    public function remove(?string $path = null): void
    {
        $path ??= $this->root;

        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }

        @rmdir($path);
    }
}
