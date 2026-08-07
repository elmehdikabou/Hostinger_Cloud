<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class Symfony implements Detector
{
    public function priority(): int
    {
        return 25;
    }

    public function detect(SiteContext $context): ?Detection
    {
        $prefix = match (true) {
            $context->hasAny('config/bundles.php', 'app/AppKernel.php') => '',
            $context->hasAny('../config/bundles.php', '../app/AppKernel.php') => '../',
            default => null,
        };

        if ($prefix === null || !$context->hasAny($prefix . 'bin/console', $prefix . 'app/console')) {
            return null;
        }

        $notes = [];
        $databases = [];

        // .env.local a la priorite sur .env, c'est l'ordre de Symfony.
        $env = $context->readFirst($prefix . '.env.local', $prefix . '.env');
        $url = null;

        if ($env !== null) {
            $values = Parse::dotenv($env->contents);
            $url = $values['DATABASE_URL'] ?? null;
        }

        if ($url === null) {
            $parameters = $context->read($prefix . 'app/config/parameters.yml');

            if ($parameters !== null) {
                $databases[] = $this->fromParametersYaml($parameters, $prefix . 'app/config/parameters.yml');
                $databases = array_values(array_filter($databases));
            }
        } else {
            $parsed = Parse::databaseUrl($url);

            if ($parsed === null) {
                $notes[] = "DATABASE_URL ne pointe pas vers MySQL : aucune base MySQL rattachee.";
            } elseif ($parsed['database'] === '') {
                $notes[] = "DATABASE_URL ne precise pas de nom de base.";
            } else {
                $databases[] = new DiscoveredDatabase(
                    database: $parsed['database'],
                    user: $parsed['user'],
                    password: $parsed['password'],
                    host: $parsed['host'],
                    port: $parsed['port'],
                    sourceFile: $env?->path ?? '.env',
                );
            }
        }

        if ($databases === [] && $notes === []) {
            $notes[] = "Aucune configuration de base lisible (.env ou parameters.yml).";
        }

        return new Detection(
            app: 'symfony',
            label: 'Symfony',
            version: $this->version($context, $prefix),
            databases: $databases,
            notes: $notes,
            evidence: $prefix . 'bin/console',
        );
    }

    private function fromParametersYaml(string $source, string $path): ?DiscoveredDatabase
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            if (preg_match('/^\s*(database_\w+)\s*:\s*(.*)$/', $line, $matches) === 1) {
                $values[$matches[1]] = trim(trim($matches[2]), "'\"");
            }
        }

        $name = $values['database_name'] ?? '';

        if ($name === '' || $name === '~' || $name === 'null') {
            return null;
        }

        return new DiscoveredDatabase(
            database: $name,
            user: $values['database_user'] ?? null,
            password: $values['database_password'] ?? null,
            host: $values['database_host'] ?? 'localhost',
            port: (int) ($values['database_port'] ?? 3306) ?: 3306,
            sourceFile: $path,
        );
    }

    private function version(SiteContext $context, string $prefix): ?string
    {
        $lock = $context->read($prefix . 'composer.lock', 2_097_152);

        if ($lock === null) {
            return null;
        }

        return Parse::composerLockVersion($lock, 'symfony/framework-bundle')
            ?? Parse::composerLockVersion($lock, 'symfony/http-kernel');
    }
}
