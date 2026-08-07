<?php

declare(strict_types=1);

namespace HostingerSpace\Scanner;

use HostingerSpace\Config;
use HostingerSpace\Detector\DetectorRegistry;
use HostingerSpace\Detector\SiteContext;
use HostingerSpace\Model\Site;
use HostingerSpace\Transport\Shell;
use HostingerSpace\Transport\Transport;

/**
 * Parcourt l'hebergement et identifie chaque site.
 *
 * Chez Hostinger, l'arborescence est reguliere :
 *
 *     ~/domains/<domaine>/public_html    racine servie du domaine
 *     ~/public_html                      domaine principal
 *
 * On descend aussi dans les sous-dossiers, parce qu'un « /blog » WordPress
 * range sous un site vitrine statique est frequent — et que sa base
 * passerait sinon pour orpheline.
 */
final class SiteScanner
{
    /**
     * Sous-dossiers appartenant a une application connue, ou purement
     * techniques : y chercher une seconde application n'a pas de sens et
     * couterait des dizaines d'allers-retours par site.
     */
    private const SKIP_DIRS = [
        'wp-admin', 'wp-content', 'wp-includes', 'vendor', 'node_modules',
        'administrator', 'libraries', 'components', 'modules', 'plugins', 'themes',
        'templates', 'cache', 'tmp', 'temp', 'logs', 'log', 'storage', 'var',
        'bootstrap', 'config', 'bin', 'lib', 'src', 'app', 'core', 'includes',
        'include', 'inc', 'assets', 'static', 'public', 'media', 'images', 'img',
        'css', 'js', 'fonts', 'upload', 'uploads', 'files', 'download', 'downloads',
        'backup', 'backups', 'cgi-bin', 'translations', 'locale', 'languages',
        'documentation', 'docs', 'vendor-bin', 'build', 'dist', '.well-known',
    ];

    private readonly int $maxDepth;

    public function __construct(
        private readonly Transport $transport,
        private readonly Config $config,
        private readonly DetectorRegistry $registry = new DetectorRegistry(),
    ) {
        $this->maxDepth = max(1, $this->config->int('analysis.max_depth', 3));
    }

    /**
     * @param (callable(string): void)|null $progress Appele avec chaque site trouve.
     *
     * @return array<int,Site>
     */
    public function scan(?callable $progress = null): array
    {
        $home = rtrim($this->transport->home(), '/');
        $roots = $this->documentRoots($home);
        $sites = [];

        foreach ($roots as $path => $domain) {
            $site = $this->inspect($path, $domain, $home, null, '/');

            if ($site === null) {
                continue;
            }

            $sites[] = $site;
            $progress?->call($this, $site->displayName());

            foreach ($this->nested($path, $domain, $home, $site->key, 1) as $child) {
                $sites[] = $child;
                $progress?->call($this, $child->displayName());
            }
        }

        return $sites;
    }

    /**
     * Racines servies, dedoublonnees.
     *
     * Chez Hostinger, ~/public_html est souvent un lien vers le dossier du
     * domaine principal : sans resolution, ce site serait compte deux fois et
     * sa base apparaitrait comme partagee entre deux sites imaginaires.
     *
     * @return array<string,string> Chemin absolu => domaine.
     */
    private function documentRoots(string $home): array
    {
        $candidates = [];
        $domainsDir = $this->transport->resolvePath($this->config->string('paths.domains_dir') ?? '~/domains');

        foreach ($this->transport->listDir($domainsDir) as $entry) {
            if (!$entry->isDir || str_starts_with($entry->name, '.')) {
                continue;
            }

            $public = $entry->path . '/public_html';
            $candidates[$this->transport->isDir($public) ? $public : $entry->path] = $entry->name;
        }

        $main = $home . '/public_html';

        if ($this->transport->isDir($main)) {
            $candidates[$main] = 'domaine principal';
        }

        foreach ($this->config->stringList('paths.extra_roots') as $extra) {
            $path = rtrim($this->transport->resolvePath($extra), '/');

            if ($this->transport->isDir($path)) {
                $candidates[$path] = basename($path);
            }
        }

        return $this->deduplicate($candidates);
    }

    /**
     * @param array<string,string> $candidates
     *
     * @return array<string,string>
     */
    private function deduplicate(array $candidates): array
    {
        $paths = array_keys($candidates);

        if ($paths === []) {
            return [];
        }

        // Une seule commande pour resoudre tous les liens d'un coup.
        $script = sprintf(
            'for p in %s; do printf "%%s\t%%s\n" "$p" "$(readlink -f "$p" 2>/dev/null || echo "$p")"; done',
            implode(' ', array_map(Shell::quote(...), $paths)),
        );

        $real = [];

        foreach ($this->transport->exec($script)->lines() as $line) {
            [$given, $resolved] = array_pad(explode("\t", $line, 2), 2, '');

            if ($given !== '') {
                $real[$given] = $resolved !== '' ? $resolved : $given;
            }
        }

        $unique = [];
        $seen = [];

        foreach ($candidates as $path => $domain) {
            $resolved = $real[$path] ?? $path;

            if (isset($seen[$resolved])) {
                // On garde le nom de domaine explicite plutot que
                // « domaine principal », plus parlant dans l'interface.
                if ($domain !== 'domaine principal' && $unique[$seen[$resolved]] === 'domaine principal') {
                    unset($unique[$seen[$resolved]]);
                    $unique[$path] = $domain;
                    $seen[$resolved] = $path;
                }

                continue;
            }

            $unique[$path] = $domain;
            $seen[$resolved] = $path;
        }

        return $unique;
    }

    private function inspect(string $path, string $domain, string $home, ?string $parentKey, string $mountPath): ?Site
    {
        $key = $this->keyFor($path, $home);
        $context = new SiteContext($this->transport, $path, $domain);
        $detection = $this->registry->detect($context);

        $site = new Site(
            key: $key,
            path: $path,
            domain: $domain,
            app: $detection->app,
            appLabel: $detection->label,
            version: $detection->version,
            evidence: $detection->evidence,
            parentKey: $parentKey,
            mountPath: $mountPath,
        );

        $site->discovered = $detection->databases;
        $site->notes = $detection->notes;

        return $site;
    }

    /**
     * Cherche des applications imbriquees sous une racine deja identifiee.
     *
     * @return array<int,Site>
     */
    private function nested(string $path, string $domain, string $home, string $parentKey, int $depth): array
    {
        if ($depth >= $this->maxDepth) {
            return [];
        }

        $found = [];

        foreach ($this->transport->listDir($path) as $entry) {
            if (!$entry->isDir || $this->shouldSkip($entry->name)) {
                continue;
            }

            $mountPath = rtrim(str_replace($this->rootOf($parentKey, $home), '', $entry->path), '/');
            $child = $this->inspect(
                $entry->path,
                $domain,
                $home,
                $parentKey,
                $mountPath === '' ? '/' : $mountPath,
            );

            if ($child === null) {
                continue;
            }

            // Un sous-dossier n'est retenu que s'il est une vraie application :
            // sinon chaque dossier d'images deviendrait un « site statique ».
            if ($this->isRealApp($child)) {
                $found[] = $child;

                // On ne descend pas sous une application identifiee : ses
                // propres sous-dossiers lui appartiennent.
                continue;
            }

            foreach ($this->nested($entry->path, $domain, $home, $parentKey, $depth + 1) as $grandChild) {
                $found[] = $grandChild;
            }
        }

        return $found;
    }

    private function isRealApp(Site $site): bool
    {
        return $site->discovered !== []
            || in_array($site->app, ['wordpress', 'laravel', 'symfony', 'joomla', 'prestashop', 'drupal', 'magento', 'phpmyadmin', 'adminer'], true);
    }

    private function shouldSkip(string $name): bool
    {
        if (str_starts_with($name, '.') || str_starts_with($name, '_')) {
            return true;
        }

        $lower = strtolower($name);

        if (in_array($lower, self::SKIP_DIRS, true) || str_starts_with($lower, 'wp-')) {
            return true;
        }

        foreach ($this->config->stringList('paths.ignore') as $ignored) {
            if ($lower === strtolower(trim($ignored, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function keyFor(string $path, string $home): string
    {
        $key = str_starts_with($path, $home . '/') ? substr($path, strlen($home) + 1) : $path;

        return trim($key, '/');
    }

    private function rootOf(string $parentKey, string $home): string
    {
        return $home . '/' . $parentKey;
    }
}
