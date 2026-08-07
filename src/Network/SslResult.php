<?php

declare(strict_types=1);

namespace HostingerSpace\Network;

final readonly class SslResult
{
    public function __construct(
        public ?int $expiresAt,
        public ?string $issuer,
        public ?string $note,
    ) {
    }

    public function daysLeft(?int $now = null): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor(($this->expiresAt - ($now ?? time())) / 86_400);
    }
}
