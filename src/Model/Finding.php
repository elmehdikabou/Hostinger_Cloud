<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

/**
 * Un constat issu de l'analyse : ce que l'utilisateur doit regarder.
 */
final readonly class Finding
{
    /** @param array<int,string> $actions Ce qu'on peut faire, formule pour un humain. */
    public function __construct(
        public FindingKind $kind,
        public Severity $severity,
        public string $title,
        public string $detail,
        /** 'database', 'site' ou 'domain'. */
        public string $subjectType,
        public string $subject,
        public array $actions = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'subject_type' => $this->subjectType,
            'subject' => $this->subject,
            'actions' => $this->actions,
        ];
    }
}
