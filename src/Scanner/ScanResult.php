<?php

declare(strict_types=1);

namespace HostingerSpace\Scanner;

use HostingerSpace\Analysis\Analysis;
use HostingerSpace\Model\Site;
use HostingerSpace\Mysql\DatabaseInventory;

final readonly class ScanResult
{
    /**
     * @param array<int,Site>   $sites
     * @param array<int,string> $errors Incidents non bloquants rencontres pendant le scan.
     */
    public function __construct(
        public int $startedAt,
        public int $finishedAt,
        public string $host,
        public string $mode,
        public array $sites,
        public DatabaseInventory $inventory,
        public Analysis $analysis,
        public array $errors = [],
    ) {
    }

    public function duration(): int
    {
        return max(0, $this->finishedAt - $this->startedAt);
    }

    public function totalDiskUsage(): int
    {
        return array_sum(array_map(static fn (Site $s): int => $s->sizeBytes, $this->sites));
    }

    /** @return array<string,int|string> */
    public function summary(): array
    {
        return [
            'sites' => count($this->sites),
            'bases' => count($this->inventory->databases),
            'orphelines' => count($this->analysis->orphans),
            'constats' => count($this->analysis->findings),
            'disque' => $this->totalDiskUsage(),
            'bases_taille' => $this->inventory->totalSize(),
        ];
    }
}
