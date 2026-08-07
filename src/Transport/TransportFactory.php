<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

use HostingerSpace\Config;

final class TransportFactory
{
    public static function fromConfig(Config $config): Transport
    {
        return match ($config->mode()) {
            'local' => new LocalTransport($config->string('paths.home') === '~' ? null : $config->string('paths.home')),
            'ssh' => new SshTransport(
                host: (string) $config->string('ssh.host', ''),
                port: $config->int('ssh.port', 22),
                username: (string) $config->string('ssh.username', ''),
                password: $config->string('ssh.password'),
                privateKeyPath: $config->string('ssh.private_key_path'),
                privateKeyPassphrase: $config->string('ssh.private_key_passphrase'),
                timeout: $config->int('ssh.timeout', 30),
                expectedFingerprint: $config->string('ssh.host_key_fingerprint'),
            ),
        };
    }
}
