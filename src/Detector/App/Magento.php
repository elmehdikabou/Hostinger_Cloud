<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class Magento implements Detector
{
    public function priority(): int
    {
        return 45;
    }

    public function detect(SiteContext $context): ?Detection
    {
        $prefix = match (true) {
            $context->hasAny('app/etc/env.php', 'app/etc/local.xml') => '',
            $context->hasAny('../app/etc/env.php', '../app/etc/local.xml') => '../',
            default => null,
        };

        if ($prefix === null) {
            return null;
        }

        $notes = [];
        $databases = [];
        $environment = $context->read($prefix . 'app/etc/env.php');

        if ($environment !== null) {
            $values = Parse::phpArrayEntries($environment);
            $name = $values['dbname'] ?? '';

            if (trim($name) !== '') {
                [$host, $port] = Parse::hostAndPort($values['host'] ?? 'localhost');

                $databases[] = new DiscoveredDatabase(
                    database: $name,
                    user: $values['username'] ?? null,
                    password: $values['password'] ?? null,
                    host: $host,
                    port: $port,
                    tablePrefix: $values['table_prefix'] ?? null,
                    sourceFile: $prefix . 'app/etc/env.php',
                );
            } else {
                $notes[] = "app/etc/env.php lu, mais aucune cle « dbname » exploitable.";
            }
        } else {
            $notes[] = "Magento 1 detecte (app/etc/local.xml) : configuration XML non analysee.";
        }

        return new Detection(
            app: 'magento',
            label: 'Magento',
            version: $this->version($context, $prefix),
            databases: $databases,
            notes: $notes,
            evidence: $prefix . ($environment !== null ? 'app/etc/env.php' : 'app/etc/local.xml'),
        );
    }

    private function version(SiteContext $context, string $prefix): ?string
    {
        $lock = $context->read($prefix . 'composer.lock', 2_097_152);

        if ($lock === null) {
            return null;
        }

        return Parse::composerLockVersion($lock, 'magento/product-community-edition')
            ?? Parse::composerLockVersion($lock, 'magento/framework');
    }
}
