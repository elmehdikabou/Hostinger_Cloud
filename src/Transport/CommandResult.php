<?php

declare(strict_types=1);

namespace HostingerSpace\Transport;

/**
 * Resultat d'une commande shell, quel que soit le transport.
 */
final readonly class CommandResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    public function trimmed(): string
    {
        return trim($this->stdout);
    }

    /**
     * Lignes non vides de la sortie standard.
     *
     * @return array<int,string>
     */
    public function lines(): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $this->stdout) ?: [];

        return array_values(array_filter(array_map(trim(...), $lines), static fn (string $l): bool => $l !== ''));
    }

    public function failureMessage(): string
    {
        $detail = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return "commande sortie en code {$this->exitCode}" . ($detail !== '' ? " : {$detail}" : '');
    }
}
