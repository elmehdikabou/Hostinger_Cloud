<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class Drupal implements Detector
{
    public function priority(): int
    {
        return 40;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if (!$context->hasAny('core/lib/Drupal.php', 'includes/bootstrap.inc')) {
            return null;
        }

        $settings = $context->readFirst('sites/default/settings.php', 'sites/default/settings.local.php');
        $notes = [];
        $databases = [];

        if ($settings === null) {
            $notes[] = "sites/default/settings.php illisible (souvent en lecture seule) : rattachement impossible.";
        } else {
            $values = Parse::phpArrayEntries($settings->contents);
            $driver = strtolower($values['driver'] ?? 'mysql');
            $name = $values['database'] ?? '';

            if (!str_contains($driver, 'mysql')) {
                $notes[] = "Pilote « {$driver} » : ce site n'utilise pas MySQL.";
            } elseif (trim($name) === '') {
                $notes[] = "settings.php lu, mais aucune cle « database » exploitable.";
            } else {
                [$host, $port] = Parse::hostAndPort($values['host'] ?? 'localhost');

                $databases[] = new DiscoveredDatabase(
                    database: $name,
                    user: $values['username'] ?? null,
                    password: $values['password'] ?? null,
                    host: $host,
                    port: (int) ($values['port'] ?? 0) ?: $port,
                    tablePrefix: $values['prefix'] ?? null,
                    sourceFile: $settings->path,
                );
            }
        }

        return new Detection(
            app: 'drupal',
            label: 'Drupal',
            version: $this->version($context),
            databases: $databases,
            notes: $notes,
            evidence: $settings?->path ?? 'core/lib/Drupal.php',
        );
    }

    private function version(SiteContext $context): ?string
    {
        $modern = $context->read('core/lib/Drupal.php', 65_536);

        if ($modern !== null) {
            $version = Parse::cleanVersion(Parse::phpClassConstant($modern, 'VERSION'));

            if ($version !== null) {
                return $version;
            }
        }

        $legacy = $context->read('includes/bootstrap.inc', 131_072);

        return $legacy === null ? null : Parse::cleanVersion(Parse::phpDefine($legacy, 'VERSION'));
    }
}
