<?php

declare(strict_types=1);

namespace HostingerSpace\Analysis;

use HostingerSpace\Model\DatabaseLink;
use HostingerSpace\Model\Finding;
use HostingerSpace\Model\Severity;

/**
 * Resultat du rapprochement entre les sites et les bases.
 */
final readonly class Analysis
{
    /**
     * @param array<int,DatabaseLink>          $links
     * @param array<int,Finding>               $findings
     * @param array<int,string>                $orphans   Bases qu'aucun site ne reference.
     * @param array<string,array<int,string>>  $usage     Nom de base => cles des sites qui l'utilisent.
     */
    public function __construct(
        public array $links,
        public array $findings,
        public array $orphans,
        public array $usage,
        /** Vrai si toutes les configurations de site ont pu etre lues. */
        public bool $sitesFullyRead,
    ) {
    }

    /** @return array<int,Finding> */
    public function findingsBySeverity(): array
    {
        $findings = $this->findings;

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => [$a->severity->weight(), $a->kind->value, $a->subject]
                <=> [$b->severity->weight(), $b->kind->value, $b->subject]
        );

        return $findings;
    }

    public function countBySeverity(Severity $severity): int
    {
        return count(array_filter($this->findings, static fn (Finding $f): bool => $f->severity === $severity));
    }

    /** @return array<int,string> */
    public function sitesUsing(string $database): array
    {
        return $this->usage[$database] ?? [];
    }
}
