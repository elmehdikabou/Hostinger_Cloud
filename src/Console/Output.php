<?php

declare(strict_types=1);

namespace HostingerSpace\Console;

/**
 * Sortie terminal : couleurs, tableaux, formats lisibles.
 *
 * Les couleurs sont desactivees automatiquement quand la sortie n'est pas un
 * terminal, pour qu'une redirection vers un fichier ou un courriel ne soit
 * pas truffee de codes d'echappement.
 */
final class Output
{
    private readonly bool $colors;

    public function __construct(private readonly mixed $stream = STDOUT)
    {
        $this->colors = self::supportsColors($stream);
    }

    private static function supportsColors(mixed $stream): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        return is_resource($stream)
            && function_exists('posix_isatty')
            && @posix_isatty($stream);
    }

    public function write(string $text = ''): void
    {
        fwrite($this->stream, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write($text . PHP_EOL);
    }

    public function title(string $text): void
    {
        $this->line();
        $this->line($this->paint($text, '1'));
        $this->line($this->paint(str_repeat('─', min(mb_strlen($text), 72)), '2'));
    }

    public function success(string $text): void
    {
        $this->line($this->paint('  ✓ ', '32') . $text);
    }

    public function warn(string $text): void
    {
        $this->line($this->paint('  ! ', '33') . $text);
    }

    public function error(string $text): void
    {
        $this->line($this->paint('  ✗ ', '31') . $text);
    }

    public function info(string $text): void
    {
        $this->line($this->paint('  · ', '2') . $text);
    }

    public function dim(string $text): void
    {
        $this->line($this->paint($text, '2'));
    }

    public function step(string $text): void
    {
        $this->line($this->paint('▸ ', '36') . $text);
    }

    /** @param array<string,string> $pairs */
    public function pairs(array $pairs, int $width = 22): void
    {
        foreach ($pairs as $label => $value) {
            $this->line('  ' . $this->paint(str_pad($label, $width), '2') . $value);
        }
    }

    /**
     * @param array<int,string>             $headers
     * @param array<int,array<int,string>>  $rows
     * @param array<int,bool>|null          $rightAlign
     */
    public function table(array $headers, array $rows, ?array $rightAlign = null): void
    {
        if ($rows === []) {
            $this->dim('  (aucun)');

            return;
        }

        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }

        $format = static function (array $cells) use ($widths, $rightAlign): string {
            $parts = [];

            foreach ($cells as $index => $cell) {
                $pad = $widths[$index] - mb_strlen($cell);
                $parts[] = ($rightAlign[$index] ?? false)
                    ? str_repeat(' ', max(0, $pad)) . $cell
                    : $cell . str_repeat(' ', max(0, $pad));
            }

            return '  ' . rtrim(implode('  ', $parts));
        };

        $this->line($this->paint($format($headers), '1'));
        $this->line($this->paint($format(array_map(
            static fn (int $w): string => str_repeat('─', $w),
            $widths
        )), '2'));

        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    public function paint(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    public static function bytes(int $bytes): string
    {
        return \HostingerSpace\Analysis\Linker::humanBytes($bytes);
    }

    public static function date(?int $timestamp): string
    {
        return $timestamp === null ? '—' : date('d/m/Y', $timestamp);
    }

    public static function dateTime(?int $timestamp): string
    {
        return $timestamp === null ? '—' : date('d/m/Y H:i', $timestamp);
    }

    /** Duree relative en francais : « il y a 3 jours ». */
    public static function since(?int $timestamp, ?int $now = null): string
    {
        if ($timestamp === null) {
            return '—';
        }

        $seconds = ($now ?? time()) - $timestamp;
        $future = $seconds < 0;
        $seconds = abs($seconds);

        $value = match (true) {
            $seconds < 3600 => max(1, (int) round($seconds / 60)) . ' min',
            $seconds < 86_400 => max(1, (int) round($seconds / 3600)) . ' h',
            $seconds < 2_592_000 => max(1, (int) round($seconds / 86_400)) . ' j',
            $seconds < 31_536_000 => max(1, (int) round($seconds / 2_592_000)) . ' mois',
            default => max(1, (int) round($seconds / 31_536_000)) . ' an(s)',
        };

        return $future ? "dans {$value}" : "il y a {$value}";
    }
}
