<?php

declare(strict_types=1);

namespace HostingerSpace\Console;

use HostingerSpace\Config;
use HostingerSpace\Storage\Database;
use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Transport\Transport;
use HostingerSpace\Transport\TransportFactory;

/**
 * Ce que partagent les commandes : configuration, stockage, connexion.
 * Tout est charge a la demande, pour qu'une commande comme « init » ne
 * reclame pas une configuration qui n'existe pas encore.
 */
final class Context
{
    private ?Config $config = null;
    private ?Database $database = null;
    private ?Transport $transport = null;

    public function __construct(
        public readonly ?string $configPath = null,
        /** En mode demonstration, on lit la base fictive et jamais la configuration reelle. */
        public readonly bool $demo = false,
    ) {
    }

    public function config(): Config
    {
        return $this->config ??= Config::load($this->configPath);
    }

    public function database(): Database
    {
        return $this->database ??= new Database($this->databasePath());
    }

    public function databasePath(): string
    {
        return $this->demo
            ? $this->rootDir() . '/var/demo.sqlite'
            : $this->config()->storagePath();
    }

    public function repository(): ScanRepository
    {
        return new ScanRepository($this->database());
    }

    public function transport(): Transport
    {
        return $this->transport ??= TransportFactory::fromConfig($this->config());
    }

    public function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
