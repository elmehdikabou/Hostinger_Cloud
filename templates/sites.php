<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<int,array<string,mixed>> $sites
 * @var string                         $filter
 */

$apps = [];

foreach ($sites as $site) {
    $apps[(string) $site['app']] = (string) $site['app_label'];
}

asort($apps);

$visible = $filter === ''
    ? $sites
    : array_values(array_filter($sites, static fn (array $s): bool => (string) $s['app'] === $filter));

?>
<div class="page-head">
    <div>
        <h1>Sites</h1>
        <p class="subtitle"><?= count($visible) ?> site(s) sur <?= count($sites) ?>, avec la ou les bases rattachées.</p>
    </div>
</div>

<div class="filters">
    <a href="<?= Fmt::url('/sites') ?>" class="<?= $filter === '' ? 'active' : '' ?>">Tous</a>
    <?php foreach ($apps as $app => $label) : ?>
        <a href="<?= Fmt::url('/sites?app=' . rawurlencode($app)) ?>" class="<?= $filter === $app ? 'active' : '' ?>">
            <?= Fmt::e($label) ?>
        </a>
    <?php endforeach ?>
</div>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Site</th>
                <th>Technologie</th>
                <th>Base(s) de données</th>
                <th class="num">Taille</th>
                <th class="num">Fichiers</th>
                <th class="num">Activité</th>
                <th>HTTP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($visible as $site) : ?>
                <tr>
                    <td>
                        <a href="<?= Fmt::siteUrl((string) $site['site_key']) ?>"><?= Fmt::e(hs_site_name($site)) ?></a>
                        <?php if ($site['parent_key'] !== null) : ?>
                            <span class="badge neutral">imbriqué</span>
                        <?php endif ?>
                    </td>
                    <td><?= hs_app_tag((string) $site['app'], (string) $site['app_label'], $site['version']) ?></td>
                    <td>
                        <?php if ($site['links'] === []) : ?>
                            <span class="muted">—</span>
                        <?php else : ?>
                            <?php foreach ($site['links'] as $link) : ?>
                                <div>
                                    <a href="<?= Fmt::databaseUrl((string) $link['database_name']) ?>" class="mono"><?= Fmt::e((string) $link['database_name']) ?></a>
                                    <?php if ($link['state'] !== 'linked') : ?>
                                        <span class="badge <?= Fmt::e(Fmt::stateClass((string) $link['state'])) ?>">
                                            <?= Fmt::e(Fmt::stateLabel((string) $link['state'])) ?>
                                        </span>
                                    <?php endif ?>
                                </div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </td>
                    <td class="num"><?= Fmt::e(Fmt::bytes($site['size_bytes'])) ?></td>
                    <td class="num muted"><?= Fmt::e(Fmt::number($site['file_count'])) ?></td>
                    <td class="num muted"><?= Fmt::e(Fmt::since($site['last_modified_at'])) ?></td>
                    <td>
                        <?php if ($site['http_status'] === null) : ?>
                            <span class="muted">—</span>
                        <?php else : ?>
                            <span class="badge <?= Fmt::e(Fmt::httpClass($site['http_status'])) ?>"><?= (int) $site['http_status'] ?></span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
