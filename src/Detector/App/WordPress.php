<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class WordPress implements Detector
{
    public function priority(): int
    {
        return 10;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if (!$context->hasAny('wp-config.php', 'wp-load.php', 'wp-includes', 'wp-settings.php')) {
            return null;
        }

        // WordPress accepte un wp-config.php place un cran au-dessus de la
        // racine web — pratique courante pour le sortir de l'espace public.
        $config = $context->readFirst('wp-config.php', '../wp-config.php');
        $notes = [];
        $databases = [];

        if ($config === null) {
            $notes[] = "Aucun wp-config.php lisible : la base rattachee n'a pas pu etre determinee.";
        } else {
            $defines = Parse::phpDefines($config->contents);
            $name = $defines['DB_NAME'] ?? null;

            if ($name === null) {
                $notes[] = "wp-config.php trouve mais DB_NAME n'y est pas une chaine litterale " .
                    "(valeur calculee ou variable d'environnement) : rattachement impossible.";
            } else {
                [$host, $port] = Parse::hostAndPort($defines['DB_HOST'] ?? 'localhost');

                $databases[] = new DiscoveredDatabase(
                    database: $name,
                    user: $defines['DB_USER'] ?? null,
                    password: $defines['DB_PASSWORD'] ?? null,
                    host: $host,
                    port: $port,
                    tablePrefix: Parse::phpVariable($config->contents, 'table_prefix'),
                    sourceFile: $config->path,
                );
            }

            if (isset($defines['MULTISITE']) || str_contains($config->contents, "'MULTISITE', true")) {
                $notes[] = "Installation multisite : plusieurs domaines partagent cette base.";
            }

            if (($defines['WP_DEBUG'] ?? '') === '1' || preg_match("/define\s*\(\s*'WP_DEBUG'\s*,\s*true/i", $config->contents) === 1) {
                $notes[] = "WP_DEBUG est actif : les erreurs PHP peuvent s'afficher aux visiteurs.";
            }
        }

        return new Detection(
            app: 'wordpress',
            label: 'WordPress',
            version: $this->version($context),
            databases: $databases,
            notes: $notes,
            evidence: $config?->path ?? 'wp-includes/',
        );
    }

    private function version(SiteContext $context): ?string
    {
        $source = $context->read('wp-includes/version.php', 32_768);

        if ($source === null) {
            return null;
        }

        return Parse::cleanVersion(Parse::phpVariable($source, 'wp_version'));
    }
}
