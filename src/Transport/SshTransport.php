<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Transport SSH, en PHP pur (phpseclib3) : aucun binaire ssh requis, l'outil
 * fonctionne donc aussi bien depuis un poste Windows que depuis un hebergement
 * mutualise ou exec() est desactive.
 *
 * L'empreinte de la cle publique du serveur est verifiee AVANT l'envoi des
 * identifiants : si tu as epingle « host_key_fingerprint », aucun mot de passe
 * ne partira vers un serveur qui ne serait pas le tien.
 */
final class SshTransport implements Transport
{
    /**
     * phpseclib3 publie ces types en constantes globales (NET_SFTP_TYPE_*)
     * definies au chargement de la classe, pas en constantes de classe. On fige
     * donc les valeurs du protocole SFTP ici plutot que de dependre de l'ordre
     * de chargement des fichiers.
     */
    private const SFTP_TYPE_DIRECTORY = 2;
    private const SFTP_TYPE_SYMLINK = 3;

    private ?SFTP $connection = null;
    private ?string $home = null;
    private ?string $observedFingerprint = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 22,
        private readonly string $username = '',
        private readonly ?string $password = null,
        private readonly ?string $privateKeyPath = null,
        private readonly ?string $privateKeyPassphrase = null,
        private readonly int $timeout = 30,
        private readonly ?string $expectedFingerprint = null,
    ) {
    }

    public function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $sftp = new SFTP($this->host, $this->port, $this->timeout);

        try {
            $hostKey = $sftp->getServerPublicHostKey();
        } catch (\Throwable $e) {
            throw new TransportException(
                "Connexion impossible a {$this->host}:{$this->port} — {$e->getMessage()}",
                previous: $e
            );
        }

        if ($hostKey === false) {
            throw new TransportException("Connexion impossible a {$this->host}:{$this->port} (aucune reponse SSH).");
        }

        $this->observedFingerprint = self::fingerprint($hostKey);

        if ($this->expectedFingerprint !== null && $this->expectedFingerprint !== '') {
            $expected = trim($this->expectedFingerprint);

            if (!hash_equals($expected, $this->observedFingerprint)) {
                throw new TransportException(
                    "L'empreinte du serveur ne correspond pas a celle epinglee dans la configuration.\n" .
                    "  attendue : {$expected}\n" .
                    "  recue    : {$this->observedFingerprint}\n" .
                    "Aucun identifiant n'a ete envoye. Si tu as change d'hebergement, mets a jour " .
                    "« ssh.host_key_fingerprint » ; sinon, ne te connecte pas."
                );
            }
        }

        if (!$sftp->login($this->username, ...$this->credentials())) {
            throw new TransportException(
                "Authentification SSH refusee pour « {$this->username} » sur {$this->host}:{$this->port}. " .
                "Verifie l'utilisateur, le port et la methode d'authentification dans hPanel > Acces SSH."
            );
        }

        $sftp->enableQuietMode();
        $this->connection = $sftp;
    }

    /** @return array<int,PrivateKey|string> */
    private function credentials(): array
    {
        if ($this->privateKeyPath !== null && $this->privateKeyPath !== '') {
            if (!is_file($this->privateKeyPath)) {
                throw new TransportException("Cle privee introuvable : {$this->privateKeyPath}");
            }

            $material = file_get_contents($this->privateKeyPath);

            if ($material === false) {
                throw new TransportException("Cle privee illisible : {$this->privateKeyPath}");
            }

            try {
                $key = PublicKeyLoader::load($material, $this->privateKeyPassphrase ?? false);
            } catch (\Throwable $e) {
                throw new TransportException(
                    "Cle privee inexploitable ({$this->privateKeyPath}) : {$e->getMessage()}. " .
                    "Si elle est protegee, renseigne « ssh.private_key_passphrase ».",
                    previous: $e
                );
            }

            return $this->password !== null && $this->password !== ''
                ? [$key, $this->password]
                : [$key];
        }

        if ($this->password === null || $this->password === '') {
            throw new TransportException("Aucune methode d'authentification SSH configuree.");
        }

        return [$this->password];
    }

    private function session(): SFTP
    {
        $this->connect();

        \assert($this->connection instanceof SFTP);

        return $this->connection;
    }

    /**
     * Empreinte au format OpenSSH (« SHA256:... »), celle qu'affiche ssh-keyscan.
     */
    public static function fingerprint(string $openSshHostKey): string
    {
        $parts = explode(' ', trim($openSshHostKey));
        $blob = base64_decode($parts[1] ?? '', true);

        if ($blob === false || $blob === '') {
            return 'SHA256:(illisible)';
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /** Empreinte reellement presentee par le serveur, disponible apres connexion. */
    public function serverFingerprint(): ?string
    {
        return $this->observedFingerprint;
    }

    public function exec(string $command): CommandResult
    {
        $sftp = $this->session();
        $stdout = $sftp->exec($command);

        if ($stdout === false) {
            return new CommandResult('', 'execution refusee par le serveur', 255);
        }

        return new CommandResult(
            is_string($stdout) ? $stdout : '',
            (string) $sftp->getStdError(),
            (int) $sftp->getExitStatus(),
        );
    }

    public function read(string $path, int $maxBytes = 2_097_152): ?string
    {
        $sftp = $this->session();
        $contents = @$sftp->get($this->resolvePath($path), false, 0, $maxBytes);

        return is_string($contents) ? $contents : null;
    }

    public function exists(string $path): bool
    {
        return $this->session()->file_exists($this->resolvePath($path));
    }

    public function isDir(string $path): bool
    {
        return $this->session()->is_dir($this->resolvePath($path));
    }

    public function listDir(string $path): array
    {
        $sftp = $this->session();
        $path = rtrim($this->resolvePath($path), '/');
        $raw = @$sftp->rawlist($path);

        if (!is_array($raw)) {
            return [];
        }

        $entries = [];

        foreach ($raw as $name => $attributes) {
            $name = (string) $name;

            if ($name === '.' || $name === '..' || !is_array($attributes)) {
                continue;
            }

            $type = (int) ($attributes['type'] ?? 0);
            $isLink = $type === self::SFTP_TYPE_SYMLINK;
            $full = $path . '/' . $name;

            // rawlist() ne suit pas les liens : un lien vers un dossier est
            // annonce comme lien, pas comme dossier. Chez Hostinger, public_html
            // en est souvent un — sans cette resolution on manquerait des sites.
            $isDir = $isLink ? $sftp->is_dir($full) : $type === self::SFTP_TYPE_DIRECTORY;

            $entries[] = new DirEntry(
                name: $name,
                path: $full,
                isDir: $isDir,
                isLink: $isLink,
                size: (int) ($attributes['size'] ?? 0),
                mtime: (int) ($attributes['mtime'] ?? 0),
            );
        }

        return $entries;
    }

    public function write(string $path, string $contents, int $mode = 0o600): void
    {
        $sftp = $this->session();
        $path = $this->resolvePath($path);

        if (!$sftp->put($path, $contents)) {
            throw new TransportException("Ecriture distante impossible : {$path}");
        }

        $sftp->chmod($mode, $path);
    }

    public function delete(string $path): void
    {
        @$this->session()->delete($this->resolvePath($path), false);
    }

    public function resolvePath(string $path): string
    {
        if ($path === '~') {
            return $this->home();
        }

        if (str_starts_with($path, '~/')) {
            return rtrim($this->home(), '/') . substr($path, 1);
        }

        return $path;
    }

    public function home(): string
    {
        if ($this->home !== null) {
            return $this->home;
        }

        $result = $this->exec('printf %s "$HOME"');
        $home = $result->trimmed();

        if ($home === '') {
            $home = (string) $this->session()->pwd();
        }

        if ($home === '') {
            $home = '/home/' . $this->username;
        }

        return $this->home = rtrim($home, '/');
    }

    public function label(): string
    {
        return "ssh://{$this->username}@{$this->host}:{$this->port}";
    }

    public function disconnect(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;
    }
}
