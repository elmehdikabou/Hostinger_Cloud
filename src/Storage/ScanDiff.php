<?php

declare(strict_types=1);

namespace HostingerSpace\Storage;

/**
 * Ce qui a change entre deux scans.
 *
 * C'est la raison d'etre de l'historique : « trois nouvelles bases
 * orphelines depuis le mois dernier » se voit d'un coup d'oeil, alors qu'une
 * liste isolee ne dit jamais si la situation s'ameliore ou se degrade.
 */
final readonly class ScanDiff
{
    /**
     * @param array<int,string>                                        $sitesAdded
     * @param array<int,string>                                        $sitesRemoved
     * @param array<int,string>                                        $databasesAdded
     * @param array<int,string>                                        $databasesRemoved
     * @param array<int,string>                                        $orphansAppeared
     * @param array<int,string>                                        $orphansResolved
     * @param array<int,array{title:string,severity:string}>           $findingsAppeared
     * @param array<int,array{title:string,severity:string}>           $findingsResolved
     * @param array<int,array{name:string,before:int,after:int}>       $sizeChanges
     */
    public function __construct(
        public int $olderScanId,
        public int $newerScanId,
        public array $sitesAdded,
        public array $sitesRemoved,
        public array $databasesAdded,
        public array $databasesRemoved,
        public array $orphansAppeared,
        public array $orphansResolved,
        public array $findingsAppeared,
        public array $findingsResolved,
        public array $sizeChanges,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sitesAdded === []
            && $this->sitesRemoved === []
            && $this->databasesAdded === []
            && $this->databasesRemoved === []
            && $this->orphansAppeared === []
            && $this->orphansResolved === []
            && $this->findingsAppeared === []
            && $this->findingsResolved === []
            && $this->sizeChanges === [];
    }

    public function changeCount(): int
    {
        return count($this->sitesAdded) + count($this->sitesRemoved)
            + count($this->databasesAdded) + count($this->databasesRemoved)
            + count($this->orphansAppeared) + count($this->orphansResolved)
            + count($this->findingsAppeared) + count($this->findingsResolved)
            + count($this->sizeChanges);
    }
}
