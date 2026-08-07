<?php

use HostingerSpace\Storage\ScanDiff;
use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var ScanDiff            $diff
 * @var array<string,mixed> $olderScan
 * @var array<string,mixed> $newerScan
 */

?>
<div class="page-head">
    <div>
        <h1>Comparaison</h1>
        <p class="subtitle">
            Relevé #<?= (int) $olderScan['id'] ?> (<?= Fmt::e(Fmt::dateTime($olderScan['finished_at'])) ?>)
            → #<?= (int) $newerScan['id'] ?> (<?= Fmt::e(Fmt::dateTime($newerScan['finished_at'])) ?>)
        </p>
    </div>
    <div><a href="<?= Fmt::url('/scans') ?>">← Historique</a></div>
</div>

<?php if ($diff->isEmpty()) : ?>
    <section class="card">
        <div class="empty-state">
            <h2>Rien n'a changé</h2>
            <p>Ces deux relevés décrivent exactement le même espace.</p>
        </div>
    </section>
<?php else : ?>
    <div class="grid grid-2">
        <?php foreach ([
            'Bases apparues' => [$diff->databasesAdded, 'ok'],
            'Bases supprimées' => [$diff->databasesRemoved, 'neutral'],
            'Nouvelles orphelines' => [$diff->orphansAppeared, 'warning'],
            'Orphelines résorbées' => [$diff->orphansResolved, 'ok'],
            'Sites apparus' => [$diff->sitesAdded, 'ok'],
            'Sites disparus' => [$diff->sitesRemoved, 'neutral'],
        ] as $label => [$items, $class]) : ?>
            <?php if ($items !== []) : ?>
                <section class="card">
                    <h2><?= Fmt::e($label) ?> (<?= count($items) ?>)</h2>
                    <p>
                        <?php foreach ($items as $item) : ?>
                            <span class="badge <?= Fmt::e($class) ?>"><?= Fmt::e((string) $item) ?></span>
                        <?php endforeach ?>
                    </p>
                </section>
            <?php endif ?>
        <?php endforeach ?>
    </div>

    <?php if ($diff->findingsAppeared !== []) : ?>
        <section class="card">
            <h2>Constats apparus</h2>
            <?php foreach ($diff->findingsAppeared as $finding) : ?>
                <div class="finding <?= Fmt::e($finding['severity']) ?>">
                    <div class="stripe"></div>
                    <div class="body">
                        <div class="title"><?= Fmt::e($finding['title']) ?></div>
                    </div>
                </div>
            <?php endforeach ?>
        </section>
    <?php endif ?>

    <?php if ($diff->findingsResolved !== []) : ?>
        <section class="card">
            <h2>Constats levés</h2>
            <?php foreach ($diff->findingsResolved as $finding) : ?>
                <div class="finding info">
                    <div class="stripe"></div>
                    <div class="body">
                        <div class="title"><?= Fmt::e($finding['title']) ?></div>
                    </div>
                </div>
            <?php endforeach ?>
        </section>
    <?php endif ?>

    <?php if ($diff->sizeChanges !== []) : ?>
        <section class="card">
            <h2>Variations de taille</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Base</th>
                        <th class="num">Avant</th>
                        <th class="num">Après</th>
                        <th class="num">Variation</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diff->sizeChanges as $change) : ?>
                        <?php $delta = $change['after'] - $change['before']; ?>
                        <tr>
                            <td><a href="<?= Fmt::databaseUrl($change['name']) ?>" class="mono"><?= Fmt::e($change['name']) ?></a></td>
                            <td class="num muted"><?= Fmt::e(Fmt::bytes($change['before'])) ?></td>
                            <td class="num"><?= Fmt::e(Fmt::bytes($change['after'])) ?></td>
                            <td class="num">
                                <span class="badge <?= $delta > 0 ? 'accent' : 'warning' ?>">
                                    <?= $delta > 0 ? '+' : '−' ?><?= Fmt::e(Fmt::bytes(abs($delta))) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif ?>
<?php endif ?>
