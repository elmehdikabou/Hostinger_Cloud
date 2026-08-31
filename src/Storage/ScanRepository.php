<?php

declare(strict_types=1);

namespace HostingerSpace\Storage;

use HostingerSpace\Model\Site;
use HostingerSpace\Scanner\ScanResult;

/**
 * Ecriture et relecture des scans.
 */
final class ScanRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(ScanResult $result): int
    {
        return (int) $this->database->transaction(function (Database $db) use ($result): int {
            $db->run(
                'INSERT INTO scans (started_at, finished_at, host, mode, declared_gaps, coverage_complete, coverage_note,
                    site_count, database_count, orphan_count, finding_count, disk_bytes, database_bytes, errors)
                 VALUES (:started, :finished, :host, :mode, :gaps, :complete, :note,
                    :sites, :databases, :orphans, :findings, :disk, :dbbytes, :errors)',
                [
                    ':started' => $result->startedAt,
                    ':finished' => $result->finishedAt,
                    ':host' => $result->host,
                    ':mode' => $result->mode,
                    ':gaps' => $result->inventory->declaredGaps,
                    ':complete' => $result->inventory->complete ? 1 : 0,
                    ':note' => $result->inventory->coverageNote(),
                    ':sites' => count($result->sites),
                    ':databases' => count($result->inventory->databases),
                    ':orphans' => count($result->analysis->orphans),
                    ':findings' => count($result->analysis->findings),
                    ':disk' => $result->totalDiskUsage(),
                    ':dbbytes' => $result->inventory->totalSize(),
                    ':errors' => self::json($result->errors),
                ]
            );

            $scanId = (int) $db->pdo()->lastInsertId();

            $this->saveSites($db, $scanId, $result->sites);
            $this->saveDatabases($db, $scanId, $result);
            $this->saveLinks($db, $scanId, $result);
            $this->saveFindings($db, $scanId, $result);

            return $scanId;
        });
    }

    /** @param array<int,Site> $sites */
    private function saveSites(Database $db, int $scanId, array $sites): void
    {
        foreach ($sites as $site) {
            $db->run(
                'INSERT INTO sites (scan_id, site_key, path, domain, mount_path, parent_key, app, app_label,
                    version, evidence, size_bytes, file_count, last_modified_at, http_status, http_note,
                    final_url, ssl_expires_at, ssl_issuer, ssl_note, domain_expires_at, registrar, notes)
                 VALUES (:scan, :key, :path, :domain, :mount, :parent, :app, :label,
                    :version, :evidence, :size, :files, :modified, :status, :httpnote,
                    :url, :sslexp, :sslissuer, :sslnote, :domexp, :registrar, :notes)',
                [
                    ':scan' => $scanId,
                    ':key' => $site->key,
                    ':path' => $site->path,
                    ':domain' => $site->domain,
                    ':mount' => $site->mountPath,
                    ':parent' => $site->parentKey,
                    ':app' => $site->app,
                    ':label' => $site->appLabel,
                    ':version' => $site->version,
                    ':evidence' => $site->evidence,
                    ':size' => $site->sizeBytes,
                    ':files' => $site->fileCount,
                    ':modified' => $site->lastModifiedAt,
                    ':status' => $site->httpStatus,
                    ':httpnote' => $site->httpNote,
                    ':url' => $site->finalUrl,
                    ':sslexp' => $site->sslExpiresAt,
                    ':sslissuer' => $site->sslIssuer,
                    ':sslnote' => $site->sslNote,
                    ':domexp' => $site->domainExpiresAt,
                    ':registrar' => $site->registrar,
                    ':notes' => self::json($site->notes),
                ]
            );
        }
    }

    private function saveDatabases(Database $db, int $scanId, ScanResult $result): void
    {
        $orphans = array_flip($result->analysis->orphans);

        foreach ($result->inventory->databases as $name => $info) {
            $db->run(
                'INSERT INTO databases (scan_id, name, size_bytes, table_count, row_estimate, created_at,
                    updated_at, charset, collation, discovered_via, is_orphan, measured)
                 VALUES (:scan, :name, :size, :tables, :rows, :created, :updated, :charset, :collation,
                    :via, :orphan, :measured)',
                [
                    ':measured' => $info->measured ? 1 : 0,
                    ':scan' => $scanId,
                    ':name' => $name,
                    ':size' => $info->sizeBytes,
                    ':tables' => $info->tableCount,
                    ':rows' => $info->rowEstimate,
                    ':created' => $info->createdAt,
                    ':updated' => $info->updatedAt,
                    ':charset' => $info->charset,
                    ':collation' => $info->collation,
                    ':via' => self::json($info->discoveredVia),
                    ':orphan' => isset($orphans[$name]) ? 1 : 0,
                ]
            );
        }
    }

    private function saveLinks(Database $db, int $scanId, ScanResult $result): void
    {
        foreach ($result->analysis->links as $link) {
            $db->run(
                'INSERT INTO links (scan_id, site_key, database_name, state, db_user, db_host, db_port,
                    table_prefix, source_file, connection_name)
                 VALUES (:scan, :site, :name, :state, :user, :host, :port, :prefix, :source, :connection)',
                [
                    ':scan' => $scanId,
                    ':site' => $link->siteKey,
                    ':name' => $link->databaseName,
                    ':state' => $link->state->value,
                    ':user' => $link->user,
                    ':host' => $link->host,
                    ':port' => $link->port,
                    ':prefix' => $link->tablePrefix,
                    ':source' => $link->sourceFile,
                    ':connection' => $link->connectionName,
                ]
            );
        }
    }

    private function saveFindings(Database $db, int $scanId, ScanResult $result): void
    {
        foreach ($result->analysis->findingsBySeverity() as $finding) {
            $db->run(
                'INSERT INTO findings (scan_id, kind, severity, title, detail, subject_type, subject, actions)
                 VALUES (:scan, :kind, :severity, :title, :detail, :type, :subject, :actions)',
                [
                    ':scan' => $scanId,
                    ':kind' => $finding->kind->value,
                    ':severity' => $finding->severity->value,
                    ':title' => $finding->title,
                    ':detail' => $finding->detail,
                    ':type' => $finding->subjectType,
                    ':subject' => $finding->subject,
                    ':actions' => self::json($finding->actions),
                ]
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function latestScan(): ?array
    {
        return $this->database->first('SELECT * FROM scans ORDER BY id DESC LIMIT 1');
    }

    /** @return array<string,mixed>|null */
    public function scan(int $id): ?array
    {
        return $this->database->first('SELECT * FROM scans WHERE id = :id', [':id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function scans(int $limit = 50): array
    {
        return $this->database->all('SELECT * FROM scans ORDER BY id DESC LIMIT :limit', [':limit' => $limit]);
    }

    /** @return array<int,array<string,mixed>> */
    public function sites(int $scanId): array
    {
        return $this->database->all(
            'SELECT * FROM sites WHERE scan_id = :scan ORDER BY domain, mount_path',
            [':scan' => $scanId]
        );
    }

    /** @return array<string,mixed>|null */
    public function site(int $scanId, string $key): ?array
    {
        return $this->database->first(
            'SELECT * FROM sites WHERE scan_id = :scan AND site_key = :key',
            [':scan' => $scanId, ':key' => $key]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function databases(int $scanId): array
    {
        return $this->database->all(
            'SELECT * FROM databases WHERE scan_id = :scan ORDER BY name',
            [':scan' => $scanId]
        );
    }

    /** @return array<string,mixed>|null */
    public function databaseByName(int $scanId, string $name): ?array
    {
        return $this->database->first(
            'SELECT * FROM databases WHERE scan_id = :scan AND name = :name',
            [':scan' => $scanId, ':name' => $name]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function orphans(int $scanId): array
    {
        return $this->database->all(
            'SELECT * FROM databases WHERE scan_id = :scan AND is_orphan = 1 ORDER BY size_bytes DESC, name',
            [':scan' => $scanId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function links(int $scanId): array
    {
        return $this->database->all('SELECT * FROM links WHERE scan_id = :scan', [':scan' => $scanId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function linksForSite(int $scanId, string $siteKey): array
    {
        return $this->database->all(
            'SELECT * FROM links WHERE scan_id = :scan AND site_key = :key',
            [':scan' => $scanId, ':key' => $siteKey]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function linksForDatabase(int $scanId, string $name): array
    {
        return $this->database->all(
            'SELECT l.*, s.domain, s.mount_path, s.app_label
               FROM links l
               LEFT JOIN sites s ON s.scan_id = l.scan_id AND s.site_key = l.site_key
              WHERE l.scan_id = :scan AND l.database_name = :name',
            [':scan' => $scanId, ':name' => $name]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findings(int $scanId, ?string $severity = null): array
    {
        // L'ordre de severite est explicite : un tri alphabetique placerait
        // « critical » apres « info ».
        $sql = "SELECT * FROM findings WHERE scan_id = :scan"
            . ($severity !== null ? ' AND severity = :severity' : '')
            . " ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, kind, subject";

        $parameters = [':scan' => $scanId];

        if ($severity !== null) {
            $parameters[':severity'] = $severity;
        }

        return $this->database->all($sql, $parameters);
    }

    /** @return array<int,array<string,mixed>> */
    public function findingsForSubject(int $scanId, string $type, string $subject): array
    {
        return $this->database->all(
            "SELECT * FROM findings
              WHERE scan_id = :scan AND subject_type = :type AND subject = :subject
              ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END",
            [':scan' => $scanId, ':type' => $type, ':subject' => $subject]
        );
    }

    /**
     * Supprime les scans les plus anciens, en gardant les N derniers.
     * Sans cela, un scan quotidien ferait grossir la base indefiniment.
     */
    public function prune(int $keep = 30): int
    {
        $ids = $this->database->all(
            'SELECT id FROM scans ORDER BY id DESC LIMIT -1 OFFSET :keep',
            [':keep' => $keep]
        );

        foreach ($ids as $row) {
            $this->database->run('DELETE FROM scans WHERE id = :id', [':id' => $row['id']]);
        }

        return count($ids);
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return array<int,mixed> */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }
}
