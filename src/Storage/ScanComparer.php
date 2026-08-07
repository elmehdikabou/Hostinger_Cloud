<?php

declare(strict_types=1);

namespace HostingerSpace\Storage;

final class ScanComparer
{
    /** Variation de taille en deca de laquelle on ne signale rien. */
    private const SIZE_NOISE_THRESHOLD = 0.10;

    public function __construct(private readonly Database $database)
    {
    }

    public function compare(int $olderScanId, int $newerScanId): ScanDiff
    {
        $olderSites = $this->column('SELECT site_key FROM sites WHERE scan_id = :scan', $olderScanId);
        $newerSites = $this->column('SELECT site_key FROM sites WHERE scan_id = :scan', $newerScanId);

        $olderDatabases = $this->column('SELECT name FROM databases WHERE scan_id = :scan', $olderScanId);
        $newerDatabases = $this->column('SELECT name FROM databases WHERE scan_id = :scan', $newerScanId);

        $olderOrphans = $this->column('SELECT name FROM databases WHERE scan_id = :scan AND is_orphan = 1', $olderScanId);
        $newerOrphans = $this->column('SELECT name FROM databases WHERE scan_id = :scan AND is_orphan = 1', $newerScanId);

        return new ScanDiff(
            olderScanId: $olderScanId,
            newerScanId: $newerScanId,
            sitesAdded: array_values(array_diff($newerSites, $olderSites)),
            sitesRemoved: array_values(array_diff($olderSites, $newerSites)),
            databasesAdded: array_values(array_diff($newerDatabases, $olderDatabases)),
            databasesRemoved: array_values(array_diff($olderDatabases, $newerDatabases)),
            orphansAppeared: array_values(array_diff($newerOrphans, $olderOrphans)),
            orphansResolved: array_values(array_diff($olderOrphans, $newerOrphans)),
            findingsAppeared: $this->findingDiff($newerScanId, $olderScanId),
            findingsResolved: $this->findingDiff($olderScanId, $newerScanId),
            sizeChanges: $this->sizeChanges($olderScanId, $newerScanId),
        );
    }

    /**
     * Constats presents dans un scan et absents de l'autre.
     *
     * L'identite d'un constat est son type plus son sujet : reformuler un
     * libelle ne doit pas faire croire a un probleme nouveau.
     *
     * @return array<int,array{title:string,severity:string}>
     */
    private function findingDiff(int $scanId, int $comparedTo): array
    {
        $rows = $this->database->all(
            'SELECT kind, severity, title, subject FROM findings
              WHERE scan_id = :scan
                AND NOT EXISTS (
                    SELECT 1 FROM findings AS other
                     WHERE other.scan_id = :other
                       AND other.kind = findings.kind
                       AND other.subject = findings.subject
                )
              ORDER BY CASE severity WHEN \'critical\' THEN 0 WHEN \'warning\' THEN 1 ELSE 2 END',
            [':scan' => $scanId, ':other' => $comparedTo]
        );

        return array_map(
            static fn (array $row): array => [
                'title' => (string) $row['title'],
                'severity' => (string) $row['severity'],
            ],
            $rows
        );
    }

    /**
     * Bases dont la taille a nettement bouge.
     *
     * Une base qui gonfle vite finit par saturer le quota ; une base qui
     * fond d'un coup a perdu des donnees. Les deux meritent un regard, mais
     * pas les variations de quelques pourcents du fonctionnement normal.
     *
     * @return array<int,array{name:string,before:int,after:int}>
     */
    private function sizeChanges(int $olderScanId, int $newerScanId): array
    {
        $rows = $this->database->all(
            'SELECT a.name, a.size_bytes AS before_bytes, b.size_bytes AS after_bytes
               FROM databases a
               JOIN databases b ON b.name = a.name AND b.scan_id = :newer
              WHERE a.scan_id = :older
              ORDER BY ABS(b.size_bytes - a.size_bytes) DESC',
            [':older' => $olderScanId, ':newer' => $newerScanId]
        );

        $changes = [];

        foreach ($rows as $row) {
            $before = (int) $row['before_bytes'];
            $after = (int) $row['after_bytes'];

            if ($before === $after) {
                continue;
            }

            // Une base qui passe de 0 a quelque chose est toujours notable ;
            // sinon on exige une variation relative significative.
            $notable = $before === 0
                ? $after > 0
                : abs($after - $before) / $before >= self::SIZE_NOISE_THRESHOLD;

            if ($notable) {
                $changes[] = ['name' => (string) $row['name'], 'before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }

    /** @return array<int,string> */
    private function column(string $sql, int $scanId): array
    {
        return array_map(
            static fn (array $row): string => (string) reset($row),
            $this->database->all($sql, [':scan' => $scanId])
        );
    }
}
