<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/** @var array<int,array<string,mixed>> $databases */

$max = 1;

foreach ($databases as $database) {
    $max = max($max, (int) $database['size_bytes']);
}

?>
<div class="page-head">
    <div>
        <h1>Bases de données</h1>
        <p class="subtitle"><?= count($databases) ?> base(s) visibles, et les sites qui les utilisent.</p>
    </div>
</div>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Base</th>
                <th>Utilisée par</th>
                <th class="num">Tables</th>
                <th class="num">Lignes (est.)</th>
                <th></th>
                <th class="num">Taille</th>
                <th class="num">Dernière écriture</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($databases as $database) : ?>
                <?php $isOrphan = (int) $database['is_orphan'] === 1; ?>
                <tr>
                    <td>
                        <a href="<?= Fmt::databaseUrl((string) $database['name']) ?>" class="mono"><?= Fmt::e((string) $database['name']) ?></a>
                        <?php if ((int) $database['table_count'] === 0) : ?>
                            <span class="badge neutral">vide</span>
                        <?php endif ?>
                    </td>
                    <td>
                        <?php if ($isOrphan) : ?>
                            <span class="badge warning">aucun site</span>
                        <?php else : ?>
                            <?php foreach ($database['sites'] as $key) : ?>
                                <div><a href="<?= Fmt::siteUrl((string) $key) ?>"><?= Fmt::e((string) $key) ?></a></div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </td>
                    <td class="num"><?= Fmt::e(Fmt::number($database['table_count'])) ?></td>
                    <td class="num muted"><?= Fmt::e(Fmt::number($database['row_estimate'])) ?></td>
                    <td><?= hs_bar((int) $database['size_bytes'], $max, $isOrphan) ?></td>
                    <td class="num"><?= Fmt::e(Fmt::bytes($database['size_bytes'])) ?></td>
                    <td class="num muted"><?= Fmt::e(Fmt::since($database['updated_at'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
