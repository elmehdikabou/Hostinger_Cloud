<?php

use HostingerSpace\Web\Fmt;

/**
 * @var string                          $content
 * @var string                          $title
 * @var string                          $path
 * @var array<string,mixed>             $scan
 * @var array<int,array<string,mixed>>  $scans
 * @var array<string,int>               $counts
 * @var bool                            $demo
 */

$scan ??= [];
$scans ??= [];
$counts ??= ['critical' => 0, 'warning' => 0, 'info' => 0];
$path ??= '/';
$demo ??= false;
$scanId = (int) ($scan['id'] ?? 0);

$navigation = [
    ['/', 'Tableau de bord', null],
    ['/sites', 'Sites', (string) ($scan['site_count'] ?? '')],
    ['/databases', 'Bases de données', (string) ($scan['database_count'] ?? '')],
    ['/orphans', 'Orphelines', (string) ($scan['orphan_count'] ?? '')],
    ['/findings', 'Constats', (string) array_sum($counts)],
    ['/domains', 'Domaines & SSL', null],
    ['/scans', 'Historique', (string) count($scans)],
];

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Fmt::e($title ?? 'Inventaire') ?> — hspace</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
      <div class="sidebar-inner">
        <a class="brand" href="<?= Fmt::url('/') ?>">
            <strong>hspace</strong>
            <span><?= $demo ? 'données de démonstration' : 'inventaire Hostinger' ?></span>
        </a>

        <nav class="nav">
            <?php foreach ($navigation as [$target, $label, $count]) : ?>
                <a href="<?= Fmt::url($target) ?>" class="<?= $path === $target ? 'active' : '' ?>">
                    <span><?= Fmt::e($label) ?></span>
                    <?php if ($count !== null && $count !== '') : ?>
                        <span class="count"><?= Fmt::e($count) ?></span>
                    <?php endif ?>
                </a>
            <?php endforeach ?>
        </nav>

        <?php if ($scans !== []) : ?>
            <div class="nav-group">Relevé consulté</div>
            <form method="get" action="<?= Fmt::e($path) ?>" class="scan-picker">
                <select name="scan" aria-label="Choisir un scan">
                    <?php foreach ($scans as $option) : ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $scanId ? 'selected' : '' ?>>
                            #<?= (int) $option['id'] ?> — <?= Fmt::e(Fmt::dateTime($option['finished_at'])) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <button type="submit">Voir</button>
            </form>
        <?php endif ?>

        <?php if ($protected ?? false) : ?>
            <div class="nav-group">Session</div>
            <nav class="nav">
                <a href="/logout">Se déconnecter</a>
            </nav>
        <?php endif ?>
      </div>
    </aside>

    <main class="main">
        <?= $content ?>
    </main>
</div>
</body>
</html>
