<?php

declare(strict_types=1);

namespace HostingerSpace\Backup;

use HostingerSpace\Model\Site;
use HostingerSpace\Transport\Transport;

/**
 * Recherche les sauvegardes presentes sur le disque.
 *
 * Un hebergement accumule des sauvegardes sans qu'on s'en rende compte : dumps
 * SQL oublies apres une migration, archives de greffons WordPress, dossiers
 * « backup » laisses par un prestataire. Deux questions comptent, et elles
 * tirent en sens inverse :
 *
 *   — Les sites et bases sont-ils sauvegardes, et depuis quand ?
 *   — Ces sauvegardes sont-elles telechargeables par n'importe qui ?
 *
 * La seconde est la plus urgente. Un dump SQL sous une racine web livre la
 * totalite d'une base — comptes, mots de passe haches, donnees clients — a qui
 * devine son nom, sans qu'aucune faille soit necessaire.
 */
final class BackupScanner
{
    /** Extensions qui designent une sauvegarde, et leur nature. */
    private const FILE_KINDS = [
        'sql' => 'dump',
        'dump' => 'dump',
        'gz' => 'archive',
        'tgz' => 'archive',
        'bz2' => 'archive',
        'zip' => 'archive',
        'tar' => 'archive',
        'bak' => 'archive',
        '7z' => 'archive',
        'rar' => 'archive',
    ];

    /**
     * Dossiers que les outils de sauvegarde creent, et ou s'entassent les
     * archives. Les nommer evite de descendre dans tout l'hebergement.
     */
    private const DIRECTORY_NAMES = [
        'backup', 'backups', 'sauvegarde', 'sauvegardes', 'bak',
        'updraft', 'ai1wm-backups', 'wpvivid', 'backwpup', 'duplicator',
        'wp-snapshots', 'akeeba', 'dump', 'dumps',
    ];

    /** Ce qu'on ne fouille jamais : volumineux, et sans sauvegarde dedans. */
    private const PRUNED = ['node_modules', '.git', 'vendor', 'cache', '.cache', 'tmp'];

    public function __construct(
        private readonly Transport $transport,
        private readonly int $maxResults = 400,
    ) {
    }

    /**
     * @param array<int,Site> $sites
     *
     * @return array<int,BackupArtifact>
     */
    public function scan(array $sites): array
    {
        $home = rtrim($this->transport->home(), '/');
        $found = $this->findWithShell($home);

        if ($found === null) {
            $found = $this->findByWalking($home, $sites);
        }

        $roots = [];

        foreach ($sites as $site) {
            $roots[rtrim($site->path, '/')] = $site->key;
        }

        $artifacts = [];

        foreach ($found as $entry) {
            $relative = ltrim(substr($entry['path'], strlen($home)), '/');

            if ($relative === '') {
                continue;
            }

            [$siteKey, $reachable] = $this->locate($entry['path'], $roots);

            $artifacts[] = new BackupArtifact(
                path: $relative,
                sizeBytes: $entry['size'],
                modifiedAt: $entry['mtime'],
                kind: $entry['kind'],
                siteKey: $siteKey,
                webReachable: $reachable,
            );
        }

        // Les plus grosses d'abord : ce sont elles qui pesent sur le quota, et
        // celles dont l'exposition coute le plus cher.
        usort($artifacts, static fn (BackupArtifact $a, BackupArtifact $b): int => $b->sizeBytes <=> $a->sizeBytes);

        return array_slice($artifacts, 0, $this->maxResults);
    }

    /**
     * Rattache une sauvegarde au site qui la contient.
     *
     * @param array<string,string> $roots Racine servie => cle du site.
     *
     * @return array{0:?string,1:bool}
     */
    private function locate(string $path, array $roots): array
    {
        $best = null;
        $bestKey = null;

        foreach ($roots as $root => $key) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                // La racine la plus profonde gagne : un site monte dans un
                // sous-dossier est plus precis que son domaine parent.
                if ($best === null || strlen($root) > strlen($best)) {
                    $best = $root;
                    $bestKey = $key;
                }
            }
        }

        return [$bestKey, $best !== null];
    }

    /**
     * Recherche par « find », seule voie raisonnable sur des dizaines de Go.
     *
     * @return array<int,array{path:string,size:int,mtime:?int,kind:string}>|null
     *         Null quand le shell n'est pas utilisable : l'appelant se rabat
     *         alors sur un parcours en PHP.
     */
    private function findWithShell(string $home): ?array
    {
        $prune = implode(' -o ', array_map(
            static fn (string $d): string => '-name ' . escapeshellarg($d),
            self::PRUNED
        ));

        $extensions = implode(' -o ', array_map(
            static fn (string $e): string => '-iname ' . escapeshellarg('*.' . $e),
            array_keys(self::FILE_KINDS)
        ));

        $directories = implode(' -o ', array_map(
            static fn (string $d): string => '-iname ' . escapeshellarg($d),
            self::DIRECTORY_NAMES
        ));

        $script = sprintf(
            'find %s -maxdepth 8 \( %s \) -prune -o '
            . '\( \( -type f \( %s \) \) -o \( -type d \( %s \) \) \) '
            . '-printf "%%y\t%%s\t%%T@\t%%p\n" 2>/dev/null | head -n 2000',
            escapeshellarg($home),
            $prune,
            $extensions,
            $directories,
        );

        $result = $this->transport->exec($script);

        // Un shell absent rend 127 ; « find » sans -printf rend une erreur et
        // aucune ligne. Dans les deux cas on ne conclut pas a « aucune
        // sauvegarde » : ce serait le pire des messages rassurants.
        if ($result->exitCode === 127 || ($result->stdout === '' && $result->exitCode !== 0)) {
            return null;
        }

        $entries = [];

        foreach (explode("\n", $result->stdout) as $line) {
            $parts = explode("\t", trim($line), 4);

            if (count($parts) !== 4 || $parts[3] === '') {
                continue;
            }

            [$type, $size, $mtime, $path] = $parts;

            $entries[] = [
                'path' => $path,
                'size' => (int) $size,
                'mtime' => $mtime === '' ? null : (int) (float) $mtime,
                'kind' => $type === 'd' ? 'dossier' : ($this->kindOf($path) ?? 'archive'),
            ];
        }

        return $entries;
    }

    /**
     * Parcours en PHP pur, pour un hebergement ou le shell est desactive.
     *
     * Volontairement limite aux racines des sites : descendre partout sans
     * « find » prendrait un temps deraisonnable sur des dizaines de Go, et un
     * scan qui n'aboutit jamais ne protege personne.
     *
     * @param array<int,Site> $sites
     *
     * @return array<int,array{path:string,size:int,mtime:?int,kind:string}>
     */
    private function findByWalking(string $home, array $sites): array
    {
        $entries = [];
        $roots = [$home];

        foreach ($sites as $site) {
            $roots[] = rtrim($site->path, '/');
        }

        foreach (array_unique($roots) as $root) {
            $this->walk($root, $entries, depth: 0);
        }

        return $entries;
    }

    /**
     * @param array<int,array{path:string,size:int,mtime:?int,kind:string}> $entries
     */
    private function walk(string $directory, array &$entries, int $depth): void
    {
        if ($depth > 3 || count($entries) >= 2000) {
            return;
        }

        foreach ($this->transport->listDir($directory) as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::PRUNED, true)) {
                continue;
            }

            $path = $directory . '/' . $name;

            if ($this->transport->isDir($path)) {
                if (in_array(strtolower($name), self::DIRECTORY_NAMES, true)) {
                    $entries[] = ['path' => $path, 'size' => 0, 'mtime' => null, 'kind' => 'dossier'];

                    continue;
                }

                $this->walk($path, $entries, $depth + 1);

                continue;
            }

            $kind = $this->kindOf($name);

            if ($kind !== null) {
                $entries[] = ['path' => $path, 'size' => 0, 'mtime' => null, 'kind' => $kind];
            }
        }
    }

    /** Nature d'un fichier d'apres son extension, ou null s'il n'en est pas une. */
    private function kindOf(string $path): ?string
    {
        $name = strtolower(basename($path));

        // « base.sql.gz » est un dump, pas une archive quelconque : c'est ce
        // qu'il contient qui compte, et la double extension le dit.
        if (preg_match('/\.(sql|dump)\.(gz|bz2|zip|xz)$/', $name) === 1) {
            return 'dump';
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return self::FILE_KINDS[$extension] ?? null;
    }
}
