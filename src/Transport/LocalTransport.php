<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

/**
 * Transport local : l'application tourne directement sur l'hebergement.
 *
 * Utile si tu deposes l'outil dans un sous-domaine chez Hostinger, et
 * indispensable pour les tests : on peut faire tourner un scan complet sur
 * une arborescence fictive, sans reseau.
 */
final class LocalTransport implements Transport
{
    private ?string $home = null;

    public function __construct(private readonly ?string $homeOverride = null)
    {
    }

    public function connect(): void
    {
        // Rien a etablir.
    }

    public function exec(string $command): CommandResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(['/bin/sh', '-c', $command], $descriptors, $pipes);

        if (!is_resource($process)) {
            return new CommandResult('', "impossible de lancer le shell local", 127);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CommandResult($stdout, $stderr, proc_close($process));
    }

    public function read(string $path, int $maxBytes = 2_097_152): ?string
    {
        $path = $this->resolvePath($path);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, $maxBytes);

        return $contents === false ? null : $contents;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function isDir(string $path): bool
    {
        return is_dir($this->resolvePath($path));
    }

    public function listDir(string $path): array
    {
        $path = rtrim($this->resolvePath($path), '/');

        if (!is_dir($path)) {
            return [];
        }

        $names = @scandir($path);

        if ($names === false) {
            return [];
        }

        $entries = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $path . '/' . $name;
            $stat = @lstat($full);

            $entries[] = new DirEntry(
                name: $name,
                path: $full,
                isDir: is_dir($full),
                isLink: is_link($full),
                size: $stat === false ? 0 : (int) $stat['size'],
                mtime: $stat === false ? 0 : (int) $stat['mtime'],
            );
        }

        return $entries;
    }

    public function write(string $path, string $contents, int $mode = 0o600): void
    {
        $path = $this->resolvePath($path);

        if (@file_put_contents($path, $contents) === false) {
            throw new TransportException("Ecriture impossible : {$path}");
        }

        @chmod($path, $mode);
    }

    public function delete(string $path): void
    {
        @unlink($this->resolvePath($path));
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

        $home = $this->homeOverride
            ?? (getenv('HOME') ?: null)
            ?? (posix_getpwuid(posix_geteuid())['dir'] ?? null)
            ?? getcwd()
            ?: '/';

        return $this->home = rtrim($home, '/');
    }

    public function label(): string
    {
        return 'local:' . $this->home();
    }

    public function disconnect(): void
    {
        // Rien a fermer.
    }
}
