<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class PrestaShop implements Detector
{
    public function priority(): int
    {
        return 35;
    }

    public function detect(SiteContext $context): ?Detection
    {
        $modern = $context->read('app/config/parameters.php');
        $legacy = $context->read('config/settings.inc.php');

        if ($modern === null && $legacy === null) {
            return null;
        }

        $notes = [];
        $databases = [];
        $version = null;

        if ($modern !== null) {
            // PrestaShop 1.7 et 8 : parametres dans un tableau PHP.
            $values = Parse::phpArrayEntries($modern);
            $name = $values['database_name'] ?? '';

            if (trim($name) !== '') {
                [$host, $port] = Parse::hostAndPort($values['database_host'] ?? 'localhost');

                $databases[] = new DiscoveredDatabase(
                    database: $name,
                    user: $values['database_user'] ?? null,
                    password: $values['database_password'] ?? null,
                    host: $host,
                    port: (int) ($values['database_port'] ?? 0) ?: $port,
                    tablePrefix: $values['database_prefix'] ?? null,
                    sourceFile: 'app/config/parameters.php',
                );
            } else {
                $notes[] = "app/config/parameters.php lu, mais « database_name » est absent.";
            }

            $kernel = $context->read('app/AppKernel.php', 65_536);
            $version = $kernel === null ? null : Parse::cleanVersion(Parse::phpClassConstant($kernel, 'VERSION'));
        }

        if ($legacy !== null) {
            // PrestaShop 1.6 : parametres en constantes.
            $defines = Parse::phpDefines($legacy);
            $version ??= Parse::cleanVersion($defines['_PS_VERSION_'] ?? null);
            $name = $defines['_DB_NAME_'] ?? '';

            if ($databases === [] && trim($name) !== '') {
                [$host, $port] = Parse::hostAndPort($defines['_DB_SERVER_'] ?? 'localhost');

                $databases[] = new DiscoveredDatabase(
                    database: $name,
                    user: $defines['_DB_USER_'] ?? null,
                    password: $defines['_DB_PASSWD_'] ?? null,
                    host: $host,
                    port: $port,
                    tablePrefix: $defines['_DB_PREFIX_'] ?? null,
                    sourceFile: 'config/settings.inc.php',
                );
            }
        }

        if ($databases === [] && $notes === []) {
            $notes[] = "Configuration PrestaShop trouvee mais illisible : rattachement impossible.";
        }

        return new Detection(
            app: 'prestashop',
            label: 'PrestaShop',
            version: $version,
            databases: $databases,
            notes: $notes,
            evidence: $modern !== null ? 'app/config/parameters.php' : 'config/settings.inc.php',
        );
    }
}
