<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

$pattern = $argv[1] ?? '';
$files = glob(__DIR__ . '/cases/*.test.php') ?: [];

foreach ($files as $file) {
    $name = basename($file, '.test.php');

    if ($pattern !== '' && !str_contains($name, $pattern)) {
        continue;
    }

    $GLOBALS['__runner']->file($name);
    require $file;
}

exit($GLOBALS['__runner']->summary());
