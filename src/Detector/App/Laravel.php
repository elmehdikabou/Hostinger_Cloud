<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class Laravel implements Detector
{
    public function priority(): int
    {
        return 20;
    }

    public function detect(SiteContext $context): ?Detection
    {
        // Un projet Laravel est souvent servi depuis son sous-dossier public/,
        // la racine du site est alors un cran en dessous du projet.
        $prefix = match (true) {
            $context->hasAll('artisan', 'bootstrap/app.php') => '',
            $context->hasAll('../artisan', '../bootstrap/app.php') => '../',
            default => null,
        };

        if ($prefix === null) {
            return null;
        }

        $env = $context->readFirst($prefix . '.env.local', $prefix . '.env');
        $notes = [];
        $databases = [];

        if ($env === null) {
            $notes[] = "Aucun fichier .env : la base rattachee n'a pas pu etre determinee.";
        } else {
            $values = Parse::dotenv($env->contents);
            $connection = strtolower($values['DB_CONNECTION'] ?? 'mysql');

            if (in_array($connection, ['mysql', 'mariadb'], true)) {
                $name = $values['DB_DATABASE'] ?? null;

                if ($name === null || trim($name) === '') {
                    $notes[] = "DB_DATABASE est absent ou vide dans le .env.";
                } else {
                    $databases[] = new DiscoveredDatabase(
                        database: $name,
                        user: $values['DB_USERNAME'] ?? null,
                        password: $values['DB_PASSWORD'] ?? null,
                        host: $values['DB_HOST'] ?? 'localhost',
                        port: (int) ($values['DB_PORT'] ?? 3306) ?: 3306,
                        tablePrefix: $values['DB_PREFIX'] ?? null,
                        sourceFile: $env->path,
                        connectionName: $connection,
                    );
                }
            } elseif ($connection === 'sqlite') {
                $notes[] = "Base SQLite (DB_CONNECTION=sqlite) : aucune base MySQL rattachee.";
            } else {
                $notes[] = "Connexion « {$connection} » : ce site n'utilise pas MySQL.";
            }

            if (strtolower($values['APP_DEBUG'] ?? '') === 'true') {
                $notes[] = "APP_DEBUG=true : les traces d'erreur, y compris des identifiants, sont exposees.";
            }

            if (strtolower($values['APP_ENV'] ?? '') === 'local') {
                $notes[] = "APP_ENV=local sur un hebergement de production.";
            }
        }

        if ($prefix !== '') {
            $notes[] = "Le projet est un cran au-dessus de la racine web (racine servie : public/).";
        }

        return new Detection(
            app: 'laravel',
            label: 'Laravel',
            version: $this->version($context, $prefix),
            databases: $databases,
            notes: $notes,
            evidence: $prefix . 'artisan',
        );
    }

    private function version(SiteContext $context, string $prefix): ?string
    {
        $lock = $context->read($prefix . 'composer.lock', 1_048_576);

        if ($lock !== null) {
            $version = Parse::composerLockVersion($lock, 'laravel/framework');

            if ($version !== null) {
                return $version;
            }
        }

        $application = $context->read(
            $prefix . 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
            65_536
        );

        return $application === null ? null : Parse::cleanVersion(Parse::phpClassConstant($application, 'VERSION'));
    }
}
