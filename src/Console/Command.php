<?php

declare(strict_types=1);

namespace HostingerSpace\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /**
     * @param array<int,string> $arguments
     *
     * @return int Code de sortie du processus.
     */
    public function run(array $arguments, Output $out, Context $context): int;
}
