<?php

declare(strict_types=1);

namespace HostingerSpace\Mysql;

/**
 * Analyse la sortie du client mysql en mode --batch.
 *
 * Le format est du TSV avec une ligne d'en-tete. mysql y echappe les
 * caracteres qui casseraient le tableau (tabulation, saut de ligne,
 * antislash) et ecrit les valeurs nulles « NULL ». On defait exactement
 * cet encodage, sans quoi une valeur contenant une tabulation decalerait
 * silencieusement toutes les colonnes suivantes.
 */
final class BatchOutput
{
    /** @return list<array<string,?string>> */
    public static function parse(string $output): array
    {
        $lines = preg_split('/\r\n|\n|\r/', rtrim($output, "\r\n"));

        if ($lines === false || $lines === [] || $lines[0] === '') {
            return [];
        }

        $headers = array_map(self::unescape(...), explode("\t", array_shift($lines)));
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $cells = explode("\t", $line);
            $row = [];

            foreach ($headers as $index => $header) {
                $raw = $cells[$index] ?? null;
                $row[$header] = ($raw === null || $raw === 'NULL') ? null : self::unescape($raw);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function unescape(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }

        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $value[$i];
                continue;
            }

            $next = $value[++$i];
            $out .= match ($next) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                '0' => "\0",
                '\\' => '\\',
                default => $next,
            };
        }

        return $out;
    }
}
