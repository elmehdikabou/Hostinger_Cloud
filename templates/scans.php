<?php

use HostingerSpace\Storage\ScanDiff;
use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<int,array<string,mixed>> $scans
 * @var ScanDiff|null                  $diff
 * @var int                            $scanId
 */

?>
<div class="page-head">
    <div>
        <h1>Historique</h1>
        <p class="subtitle"><?= count($scans) ?> relevé(s) enregistré(s).</p>
    </div>
</div>

<?php if ($diff !== null && !$diff->isEmpty()) : ?>
    <section class="card">
        <h2>Depuis le relevé précédent</h2>
        <p class="subtitle"><?= $diff->changeCount() ?> changement(s) entre le #<?= $diff->olderScanId ?> et le #<?= $diff->newerScanId ?>.</p>

        <?php foreach ([
            'Bases apparues' => [$diff->databasesAdded, 'ok'],
            'Bases supprimées' => [$diff->databasesRemoved, 'neutral'],
            'Nouvelles orphelines' => [$diff->orphansAppeared, 'warning'],
            'Orphelines résorbées' => [$diff->orphansResolved, 'ok'],
            'Sites apparus' => [$diff->sitesAdded, 'ok'],
            'Sites disparus' => [$diff->sitesRemoved, 'neutral'],
        ] as $label => [$items, $class]) : ?>
            <?php if ($items !== []) : ?>
                <h3><?= Fmt::e($label) ?></h3>
                <p>
                    <?php foreach ($items as $item) : ?>
                        <span class="badge <?= Fmt::e($class) ?>"><?= Fmt::e((string) $item) ?></span>
                    <?php endforeach ?>
                </p>
            <?php endif ?>
        <?php endforeach ?>

        <p><a href="<?= Fmt::url('/diff?a=' . $diff->olderScanId . '&b=' . $diff->newerScanId) ?>">Comparaison détaillée →</a></p>
    </section>
<?php endif ?>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Relevé</th>
                <th>Terminé le</th>
                <th class="num">Durée</th>
                <th class="num">Sites</th>
                <th class="num">Bases</th>
                <th class="num">Orphelines</th>
                <th class="num">Constats</th>
                <th class="num">Poids bases</th>
                <th>Inventaire</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($scans as $index => $entry) : ?>
                <tr>
                    <td>
                        <a href="<?= Fmt::e('/?scan=' . (int) $entry['id']) ?>">#<?= (int) $entry['id'] ?></a>
                        <?php if ((int) $entry['id'] === $scanId) : ?>
                            <span class="badge accent">affiché</span>
                        <?php endif ?>
                    </td>
                    <td class="nowrap"><?= Fmt::e(Fmt::dateTime($entry['finished_at'])) ?></td>
                    <td class="num muted"><?= (int) $entry['finished_at'] - (int) $entry['started_at'] ?> s</td>
                    <td class="num"><?= Fmt::e(Fmt::number($entry['site_count'])) ?></td>
                    <td class="num"><?= Fmt::e(Fmt::number($entry['database_count'])) ?></td>
                    <td class="num"><?= Fmt::e(Fmt::number($entry['orphan_count'])) ?></td>
                    <td class="num"><?= Fmt::e(Fmt::number($entry['finding_count'])) ?></td>
                    <td class="num"><?= Fmt::e(Fmt::bytes($entry['database_bytes'])) ?></td>
                    <td>
                        <?php if ((int) $entry['coverage_complete'] === 1) : ?>
                            <span class="badge ok">complet</span>
                        <?php else : ?>
                            <span class="badge warning">partiel</span>
                        <?php endif ?>
                    </td>
                    <td class="nowrap">
                        <?php if (isset($scans[$index + 1])) : ?>
                            <a href="<?= Fmt::e('/diff?a=' . (int) $scans[$index + 1]['id'] . '&b=' . (int) $entry['id']) ?>">comparer</a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
