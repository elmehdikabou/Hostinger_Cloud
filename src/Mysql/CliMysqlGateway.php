<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

use HostingerSpace\Transport\Shell;
use HostingerSpace\Transport\Transport;

/**
 * Interroge MySQL en passant par le client en ligne de commande du serveur.
 *
 * C'est la seule voie praticable sur un mutualise Hostinger : MySQL n'y ecoute
 * que sur la boucle locale, on ne peut donc pas s'y connecter en TCP depuis
 * l'exterieur. On execute le client sur place, a travers le tunnel SSH.
 *
 * Les identifiants ne sont jamais passes en arguments : ils transitent par un
 * fichier d'options en 0600, cree pour la session et supprime a la fermeture.
 * Sinon ils apparaitraient dans la liste des processus, lisible par les autres
 * comptes de la machine mutualisee.
 */
final class CliMysqlGateway implements MysqlGateway
{
    private ?string $optionsFile = null;
    private ?string $binary = null;
    private bool $shutdownHookRegistered = false;

    public function __construct(
        private readonly Transport $transport,
        private readonly MysqlCredential $credential,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public function query(string $sql): array
    {
        $command = Shell::build($this->binary(), [
            '--defaults-extra-file=' . $this->optionsFile(),
            '--batch',
            '--connect-timeout=' . $this->connectTimeout,
            '-e',
            $sql,
        ]);

        $result = $this->transport->exec($command);

        if (!$result->ok()) {
            throw new MysqlException($this->cleanError($result->stderr, $result->stdout));
        }

        return BatchOutput::parse($result->stdout);
    }

    public function probe(): ?string
    {
        try {
            $this->query('SELECT 1');

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Localise le client. Les images recentes livrent parfois « mariadb »
     * a la place de « mysql ».
     */
    private function binary(): string
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        $result = $this->transport->exec('command -v mysql || command -v mariadb');
        $path = $result->trimmed();

        if ($path === '') {
            throw new MysqlException(
                "Aucun client MySQL sur le serveur (ni « mysql » ni « mariadb »). " .
                "L'inventaire des bases est impossible en SSH sur cet hebergement."
            );
        }

        return $this->binary = strtok($path, "\n") ?: 'mysql';
    }

    private function optionsFile(): string
    {
        if ($this->optionsFile !== null) {
            return $this->optionsFile;
        }

        $path = rtrim($this->transport->home(), '/') . '/.hspace-' . bin2hex(random_bytes(8)) . '.cnf';

        $this->transport->write($path, $this->optionsFileContents(), 0o600);
        $this->optionsFile = $path;

        if (!$this->shutdownHookRegistered) {
            // Filet de securite : meme si le scan s'interrompt sur une erreur,
            // le fichier d'identifiants ne reste pas sur le serveur.
            register_shutdown_function($this->close(...));
            $this->shutdownHookRegistered = true;
        }

        return $path;
    }

    private function optionsFileContents(): string
    {
        return implode("\n", [
            '[client]',
            'user=' . self::quoteOption($this->credential->user),
            'password=' . self::quoteOption($this->credential->password),
            'host=' . self::quoteOption($this->credential->host),
            'port=' . $this->credential->port,
            '',
        ]);
    }

    /**
     * Les fichiers d'options MySQL traitent « # » comme un debut de
     * commentaire et interpretent les antislashs. Un mot de passe contenant
     * l'un ou l'autre serait tronque en silence : on cite systematiquement.
     */
    private static function quoteOption(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** Retire le bruit habituel du client pour ne garder qu'un message utile. */
    private function cleanError(string $stderr, string $stdout): string
    {
        $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

        $message = preg_replace(
            '/^(mysql|mariadb): \[Warning\].*$/mi',
            '',
            $message
        ) ?? $message;

        $message = trim($message);

        return $message === ''
            ? "Requete MySQL refusee par le serveur ({$this->credential->label()})."
            : $message;
    }

    public function label(): string
    {
        return $this->credential->label();
    }

    public function close(): void
    {
        if ($this->optionsFile === null) {
            return;
        }

        $path = $this->optionsFile;
        $this->optionsFile = null;

        try {
            $this->transport->delete($path);
        } catch (\Throwable) {
            // La connexion est peut-etre deja tombee ; le fichier est en 0600
            // dans le dossier personnel, le risque residuel est nul.
        }
    }
}
