<?php

declare(strict_types=1);

/**
 * Lanceur de tests minimal.
 *
 * Volontairement sans dependance : l'outil doit pouvoir tourner et etre
 * verifie sur un hebergement mutualise, ou installer PHPUnit n'est pas
 * toujours possible.
 *
 * Usage : php tests/run.php [motif]
 */

final class TestRunner
{
    /** @var array<int,array{name:string,error:string}> */
    private array $failures = [];
    private int $passed = 0;
    private string $currentFile = '';

    public function file(string $name): void
    {
        $this->currentFile = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public function test(string $name, callable $body): void
    {
        try {
            $body();
            $this->passed++;
            echo "  \033[32mok\033[0m   {$name}\n";
        } catch (\Throwable $e) {
            $this->failures[] = [
                'name' => "{$this->currentFile} > {$name}",
                'error' => $e->getMessage() . "\n       " . $e->getFile() . ':' . $e->getLine(),
            ];
            echo "  \033[31mKO\033[0m   {$name}\n";
            echo "       \033[31m" . $e->getMessage() . "\033[0m\n";
        }
    }

    public function summary(): int
    {
        $failed = count($this->failures);

        echo "\n" . str_repeat('-', 60) . "\n";

        if ($failed === 0) {
            echo "\033[32m{$this->passed} tests passes.\033[0m\n";

            return 0;
        }

        echo "\033[31m{$failed} echec(s)\033[0m sur " . ($this->passed + $failed) . " tests :\n";

        foreach ($this->failures as $failure) {
            echo "  - {$failure['name']}\n       {$failure['error']}\n";
        }

        return 1;
    }
}

$GLOBALS['__runner'] = new TestRunner();

function test(string $name, callable $body): void
{
    $GLOBALS['__runner']->test($name, $body);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($message !== '' ? $message . ' — ' : '') .
            'attendu ' . var_export($expected, true) . ', recu ' . var_export($actual, true)
        );
    }
}

function assertTrue(mixed $actual, string $message = 'devrait etre vrai'): void
{
    if ($actual !== true) {
        throw new \RuntimeException($message . ' (recu ' . var_export($actual, true) . ')');
    }
}

function assertFalse(mixed $actual, string $message = 'devrait etre faux'): void
{
    if ($actual !== false) {
        throw new \RuntimeException($message . ' (recu ' . var_export($actual, true) . ')');
    }
}

function assertNull(mixed $actual, string $message = 'devrait etre null'): void
{
    if ($actual !== null) {
        throw new \RuntimeException($message . ' (recu ' . var_export($actual, true) . ')');
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException(
            ($message !== '' ? $message . ' — ' : '') .
            "« {$needle} » absent de « " . mb_substr($haystack, 0, 200) . " »"
        );
    }
}

function assertCount(int $expected, array $actual, string $message = ''): void
{
    if (count($actual) !== $expected) {
        throw new \RuntimeException(
            ($message !== '' ? $message . ' — ' : '') .
            "attendu {$expected} element(s), recu " . count($actual)
        );
    }
}

function assertThrows(string $expectedClass, callable $body, string $message = ''): \Throwable
{
    try {
        $body();
    } catch (\Throwable $e) {
        if (!$e instanceof $expectedClass) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ' — ' : '') .
                'attendu ' . $expectedClass . ', recu ' . $e::class . ' : ' . $e->getMessage()
            );
        }

        return $e;
    }

    throw new \RuntimeException(
        ($message !== '' ? $message . ' — ' : '') . "aucune exception levee (attendu {$expectedClass})"
    );
}
