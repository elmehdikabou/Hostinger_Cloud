<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

enum Severity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critique',
            self::Warning => 'A verifier',
            self::Info => 'Pour information',
        };
    }

    /** Poids de tri : le plus grave en premier. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
