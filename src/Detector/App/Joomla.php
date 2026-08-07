<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

final class Joomla implements Detector
{
    public function priority(): int
    {
        return 30;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if (!$context->exists('configuration.php') || !$context->hasAny('libraries', 'administrator')) {
            return null;
        }

        $config = $context->read('configuration.php');

        if ($config === null || !str_contains($config, 'JConfig')) {
            return null;
        }

        $values = Parse::phpVariables($config);
        $notes = [];
        $databases = [];
        $driver = strtolower($values['dbtype'] ?? 'mysqli');

        if (!str_contains($driver, 'mysql')) {
            $notes[] = "Pilote « {$driver} » : ce site n'utilise pas MySQL.";
        } elseif (trim($values['db'] ?? '') === '') {
            $notes[] = "configuration.php lu, mais la propriete \$db est vide.";
        } else {
            [$host, $port] = Parse::hostAndPort($values['host'] ?? 'localhost');

            $databases[] = new DiscoveredDatabase(
                database: $values['db'],
                user: $values['user'] ?? null,
                password: $values['password'] ?? null,
                host: $host,
                port: $port,
                tablePrefix: $values['dbprefix'] ?? null,
                sourceFile: 'configuration.php',
            );
        }

        if (($values['error_reporting'] ?? '') === 'maximum' || ($values['debug'] ?? '') === '1') {
            $notes[] = "Mode debogage actif dans configuration.php.";
        }

        return new Detection(
            app: 'joomla',
            label: 'Joomla',
            version: $this->version($context),
            databases: $databases,
            notes: $notes,
            evidence: 'configuration.php',
        );
    }

    private function version(SiteContext $context): ?string
    {
        // Joomla 4 et 5 : les composants de version sont des constantes de classe.
        $modern = $context->read('libraries/src/Version.php', 65_536);

        if ($modern !== null) {
            $major = Parse::phpClassConstant($modern, 'MAJOR_VERSION');
            $minor = Parse::phpClassConstant($modern, 'MINOR_VERSION');
            $patch = Parse::phpClassConstant($modern, 'PATCH_VERSION');

            if ($major !== null) {
                return implode('.', array_filter([$major, $minor, $patch], static fn (?string $v): bool => $v !== null));
            }
        }

        // Joomla 3 : RELEASE porte « 3.10 » et DEV_LEVEL le correctif.
        $legacy = $context->read('libraries/cms/version/version.php', 65_536);

        if ($legacy !== null) {
            $release = Parse::phpClassConstant($legacy, 'RELEASE');
            $level = Parse::phpClassConstant($legacy, 'DEV_LEVEL');

            if ($release !== null) {
                return $level !== null ? "{$release}.{$level}" : $release;
            }
        }

        return null;
    }
}
