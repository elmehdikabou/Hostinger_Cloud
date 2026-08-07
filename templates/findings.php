<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<int,array<string,mixed>> $findings
 * @var string|null                    $severity
 * @var array<string,int>              $counts
 */

$tabs = [
    '' => 'Tous (' . array_sum($counts) . ')',
    'critical' => 'Critiques (' . $counts['critical'] . ')',
    'warning' => 'À vérifier (' . $counts['warning'] . ')',
    'info' => 'Informations (' . $counts['info'] . ')',
];

?>
<div class="page-head">
    <div>
        <h1>Constats</h1>
        <p class="subtitle">Ce que l'analyse a relevé, du plus grave au plus anodin.</p>
    </div>
</div>

<div class="filters">
    <?php foreach ($tabs as $value => $label) : ?>
        <a href="<?= Fmt::url('/findings' . ($value === '' ? '' : '?severity=' . $value)) ?>"
           class="<?= ($severity ?? '') === $value ? 'active' : '' ?>"><?= Fmt::e($label) ?></a>
    <?php endforeach ?>
</div>

<section class="card">
    <?php if ($findings === []) : ?>
        <div class="empty-state">
            <h2>Rien à signaler</h2>
            <p>Aucun constat de cette catégorie sur ce relevé.</p>
        </div>
    <?php else : ?>
        <?php foreach ($findings as $finding) : ?>
            <?= hs_finding($finding) ?>
        <?php endforeach ?>
    <?php endif ?>
</section>
