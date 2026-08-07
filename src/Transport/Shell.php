<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

/**
 * Echappement shell POSIX.
 *
 * On n'utilise pas escapeshellarg() : son comportement depend du systeme qui
 * execute PHP, alors que la commande, elle, part toujours vers un shell Linux.
 */
final class Shell
{
    public static function quote(string $argument): string
    {
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /** @param array<int,string> $arguments */
    public static function build(string $binary, array $arguments): string
    {
        return $binary . ' ' . implode(' ', array_map(self::quote(...), $arguments));
    }
}
