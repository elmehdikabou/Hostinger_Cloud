<?php

declare(strict_types=1);

namespace HostingerSpace\Network;

final readonly class HttpResult
{
    public function __construct(
        public ?int $status,
        public ?string $finalUrl,
        public ?string $note,
    ) {
    }

    public function reachable(): bool
    {
        return $this->status !== null && $this->status > 0 && $this->status < 400;
    }
}
