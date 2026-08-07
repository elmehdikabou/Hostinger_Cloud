<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

/**
 * Abstraction du systeme de fichiers et du shell de l'hebergement.
 *
 * Deux implementations : LocalTransport (l'app tourne sur l'hebergement) et
 * SshTransport (l'app tourne ailleurs). Tout le reste du code ignore laquelle
 * est utilisee, ce qui permet de developper et tester hors ligne.
 */
interface Transport
{
    /**
     * Etablit la connexion. Appelable plusieurs fois sans effet de bord.
     *
     * @throws TransportException
     */
    public function connect(): void;

    public function exec(string $command): CommandResult;

    /** Lit un fichier, ou null s'il est absent ou illisible. */
    public function read(string $path, int $maxBytes = 2_097_152): ?string;

    public function exists(string $path): bool;

    public function isDir(string $path): bool;

    /** @return array<int,DirEntry> */
    public function listDir(string $path): array;

    /** Ecrit un fichier avec des permissions restrictives (fichiers temporaires sensibles). */
    public function write(string $path, string $contents, int $mode = 0o600): void;

    public function delete(string $path): void;

    /** Resout « ~ » et normalise le chemin. */
    public function resolvePath(string $path): string;

    /** Dossier personnel du compte distant. */
    public function home(): string;

    /** Libelle lisible de la connexion, pour les journaux et l'interface. */
    public function label(): string;

    public function disconnect(): void;
}
