<?php

declare(strict_types=1);

namespace HostingerSpace\Web;

use HostingerSpace\Storage\Database;
use HostingerSpace\Storage\ScanComparer;
use HostingerSpace\Storage\ScanRepository;

/**
 * Interface web de l'inventaire.
 *
 * Strictement en lecture : elle n'ecrit jamais sur l'hebergement et ne
 * supprime jamais une base. Une suppression declenchee depuis une page web,
 * sur la foi d'une heuristique, serait le pire defaut possible pour cet
 * outil — l'interface montre, explique, et donne la commande a lancer soi-meme.
 */
final class Kernel
{
    private readonly ScanRepository $repository;
    private readonly View $view;

    public function __construct(
        private readonly Database $database,
        string $templateDirectory,
        private readonly bool $demo = false,
    ) {
        $this->repository = new ScanRepository($this->database);
        $this->view = new View($templateDirectory, ['demo' => $this->demo]);
    }

    /**
     * @param array<string,string> $query
     */
    public function handle(string $path, array $query): Response
    {
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        $scans = $this->repository->scans(60);

        if ($scans === []) {
            return new Response($this->view->render('empty', ['title' => 'Aucun scan']), 200);
        }

        $scan = $this->selectScan($scans, $query['scan'] ?? null);
        $scanId = (int) $scan['id'];

        Fmt::useScan($scanId, $scanId === (int) $scans[0]['id']);

        $shared = [
            'scan' => $scan,
            'scans' => $scans,
            'scanId' => $scanId,
            'path' => $path,
            'counts' => $this->counts($scanId),
        ];

        return match ($path) {
            '/' => $this->page('dashboard', 'Tableau de bord', $shared, $this->dashboard($scanId)),
            '/sites' => $this->page('sites', 'Sites', $shared, [
                'sites' => $this->sitesWithLinks($scanId),
                'filter' => $query['app'] ?? '',
            ]),
            '/site' => $this->siteDetail($scanId, $shared, $query['key'] ?? ''),
            '/databases' => $this->page('databases', 'Bases de données', $shared, [
                'databases' => $this->databasesWithUsage($scanId),
            ]),
            '/database' => $this->databaseDetail($scanId, $shared, $query['name'] ?? ''),
            '/orphans' => $this->page('orphans', 'Bases orphelines', $shared, [
                'orphans' => $this->repository->orphans($scanId),
            ]),
            '/findings' => $this->page('findings', 'Constats', $shared, [
                'findings' => $this->repository->findings($scanId, $this->severity($query['severity'] ?? null)),
                'severity' => $this->severity($query['severity'] ?? null),
            ]),
            '/domains' => $this->page('domains', 'Domaines et certificats', $shared, [
                'sites' => array_values(array_filter(
                    $this->repository->sites($scanId),
                    static fn (array $s): bool => $s['parent_key'] === null && str_contains((string) $s['domain'], '.')
                )),
            ]),
            '/scans' => $this->page('scans', 'Historique', $shared, [
                'diff' => count($scans) > 1
                    ? (new ScanComparer($this->database))->compare((int) $scans[1]['id'], (int) $scans[0]['id'])
                    : null,
            ]),
            '/diff' => $this->diffPage($shared, $query),
            default => new Response($this->view->render('error', array_merge($shared, [
                'title' => 'Page introuvable',
                'message' => "L'adresse « {$path} » ne correspond à aucune page.",
            ])), 404),
        };
    }

    /**
     * @param array<string,mixed> $shared
     * @param array<string,mixed> $data
     */
    private function page(string $template, string $title, array $shared, array $data): Response
    {
        return new Response($this->view->render($template, array_merge($shared, $data, ['title' => $title])));
    }

    /**
     * @param array<int,array<string,mixed>> $scans
     *
     * @return array<string,mixed>
     */
    private function selectScan(array $scans, ?string $requested): array
    {
        if ($requested !== null && ctype_digit($requested)) {
            foreach ($scans as $scan) {
                if ((int) $scan['id'] === (int) $requested) {
                    return $scan;
                }
            }
        }

        return $scans[0];
    }

    private function severity(?string $value): ?string
    {
        return in_array($value, ['critical', 'warning', 'info'], true) ? $value : null;
    }

    /** @return array<string,int> */
    private function counts(int $scanId): array
    {
        $row = $this->database->first(
            "SELECT
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                SUM(CASE WHEN severity = 'warning'  THEN 1 ELSE 0 END) AS warning,
                SUM(CASE WHEN severity = 'info'     THEN 1 ELSE 0 END) AS info
             FROM findings WHERE scan_id = :scan",
            [':scan' => $scanId]
        ) ?? [];

        return [
            'critical' => (int) ($row['critical'] ?? 0),
            'warning' => (int) ($row['warning'] ?? 0),
            'info' => (int) ($row['info'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function dashboard(int $scanId): array
    {
        return [
            'findings' => array_slice($this->repository->findings($scanId), 0, 8),
            'orphans' => $this->repository->orphans($scanId),
            'biggestSites' => $this->database->all(
                'SELECT domain, mount_path, app_label, size_bytes FROM sites
                  WHERE scan_id = :scan ORDER BY size_bytes DESC LIMIT 5',
                [':scan' => $scanId]
            ),
            'biggestDatabases' => $this->database->all(
                'SELECT name, size_bytes, table_count, is_orphan FROM databases
                  WHERE scan_id = :scan ORDER BY size_bytes DESC LIMIT 5',
                [':scan' => $scanId]
            ),
            'stale' => $this->database->all(
                'SELECT domain, mount_path, app_label, last_modified_at FROM sites
                  WHERE scan_id = :scan AND last_modified_at IS NOT NULL
                  ORDER BY last_modified_at ASC LIMIT 5',
                [':scan' => $scanId]
            ),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sitesWithLinks(int $scanId): array
    {
        $links = [];

        foreach ($this->repository->links($scanId) as $link) {
            $links[(string) $link['site_key']][] = $link;
        }

        $sites = $this->repository->sites($scanId);

        foreach ($sites as $index => $site) {
            $sites[$index]['links'] = $links[(string) $site['site_key']] ?? [];
        }

        return $sites;
    }

    /** @return array<int,array<string,mixed>> */
    private function databasesWithUsage(int $scanId): array
    {
        $usage = [];

        foreach ($this->repository->links($scanId) as $link) {
            if ($link['state'] !== 'external') {
                $usage[(string) $link['database_name']][] = (string) $link['site_key'];
            }
        }

        $databases = $this->repository->databases($scanId);

        foreach ($databases as $index => $database) {
            $databases[$index]['sites'] = array_values(array_unique($usage[(string) $database['name']] ?? []));
        }

        return $databases;
    }

    /** @param array<string,mixed> $shared */
    private function siteDetail(int $scanId, array $shared, string $key): Response
    {
        $site = $this->repository->site($scanId, $key);

        if ($site === null) {
            return new Response($this->view->render('error', array_merge($shared, [
                'title' => 'Site introuvable',
                'message' => "Aucun site « {$key} » dans ce scan.",
            ])), 404);
        }

        return $this->page('site', (string) $site['domain'], $shared, [
            'site' => $site,
            'links' => $this->repository->linksForSite($scanId, $key),
            'findings' => $this->repository->findingsForSubject($scanId, 'site', $key),
        ]);
    }

    /** @param array<string,mixed> $shared */
    private function databaseDetail(int $scanId, array $shared, string $name): Response
    {
        $database = $this->repository->databaseByName($scanId, $name);

        if ($database === null) {
            return new Response($this->view->render('error', array_merge($shared, [
                'title' => 'Base introuvable',
                'message' => "Aucune base « {$name} » dans ce scan.",
            ])), 404);
        }

        return $this->page('database', (string) $database['name'], $shared, [
            'database' => $database,
            'links' => $this->repository->linksForDatabase($scanId, $name),
            'findings' => $this->repository->findingsForSubject($scanId, 'database', $name),
            'history' => $this->database->all(
                'SELECT s.id, s.finished_at, d.size_bytes, d.table_count, d.is_orphan
                   FROM databases d JOIN scans s ON s.id = d.scan_id
                  WHERE d.name = :name ORDER BY s.id DESC LIMIT 20',
                [':name' => $name]
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $shared
     * @param array<string,string> $query
     */
    private function diffPage(array $shared, array $query): Response
    {
        $scans = $shared['scans'];
        $older = isset($query['a']) && ctype_digit($query['a']) ? (int) $query['a'] : (int) ($scans[1]['id'] ?? 0);
        $newer = isset($query['b']) && ctype_digit($query['b']) ? (int) $query['b'] : (int) ($scans[0]['id'] ?? 0);

        if ($older === 0 || $newer === 0 || $this->repository->scan($older) === null || $this->repository->scan($newer) === null) {
            return new Response($this->view->render('error', array_merge($shared, [
                'title' => 'Comparaison impossible',
                'message' => "Il faut deux scans existants pour comparer.",
            ])), 400);
        }

        return $this->page('diff', 'Comparaison', $shared, [
            'diff' => (new ScanComparer($this->database))->compare($older, $newer),
            'olderScan' => $this->repository->scan($older),
            'newerScan' => $this->repository->scan($newer),
        ]);
    }
}
