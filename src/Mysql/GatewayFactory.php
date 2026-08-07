<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

use HostingerSpace\Transport\LocalTransport;
use HostingerSpace\Transport\Transport;

/**
 * Choisit la meilleure voie d'acces a MySQL selon le transport.
 *
 * En local, PDO parle directement au serveur : plus rapide et plus sur.
 * A travers SSH, on passe par le client en ligne de commande, seule option
 * quand MySQL n'ecoute que sur la boucle locale du serveur distant.
 */
final class GatewayFactory
{
    public function __construct(private readonly Transport $transport)
    {
    }

    public function for(MysqlCredential $credential): MysqlGateway
    {
        if ($this->transport instanceof LocalTransport && extension_loaded('pdo_mysql')) {
            return new PdoMysqlGateway($credential);
        }

        return new CliMysqlGateway($this->transport, $credential);
    }

    /** @return \Closure(MysqlCredential): MysqlGateway */
    public function asCallable(): \Closure
    {
        return $this->for(...);
    }
}
