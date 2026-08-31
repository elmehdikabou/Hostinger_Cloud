<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<string,mixed>            $scan
 * @var array<string,int>              $counts
 * @var array<int,array<string,mixed>> $findings
 * @var array<int,array<string,mixed>> $orphans
 * @var array<int,array<string,mixed>> $biggestSites
 * @var array<int,array<string,mixed>> $biggestDatabases
 * @var array<int,array<string,mixed>> $stale
 */

// Les bases connues par leur seul nom ne pesent pas zero octet : leur poids
// est inconnu. Les additionner reviendrait a annoncer un espace recuperable
// bien plus faible que la realite.
$measuredOrphans = array_values(array_filter($orphans, Fmt::measured(...)));
$measuredDatabases = array_values(array_filter($biggestDatabases, Fmt::measured(...)));

$orphanBytes = array_sum(array_map(static fn (array $d): int => (int) $d['size_bytes'], $measuredOrphans));
$maxSite = max(1, ...array_map(static fn (array $s): int => (int) $s['size_bytes'], $biggestSites ?: [['size_bytes' => 1]]));
$maxDatabase = max(1, ...array_map(static fn (array $d): int => (int) $d['size_bytes'], $measuredDatabases ?: [['size_bytes' => 1]]));

?>
<div class="page-head">
    <div>
        <h1>Tableau de bord</h1>
        <p class="subtitle">
            Relevé #<?= (int) $scan['id'] ?> du <?= Fmt::e(Fmt::dateTime($scan['finished_at'])) ?>
            · <?= Fmt::e((string) $scan['host']) ?>
        </p>
    </div>
</div>

<?php if ((int) $scan['coverage_complete'] !== 1) : ?>
    <div class="notice warning">
        <strong>Inventaire partiel</strong>
        <?= Fmt::e((string) $scan['coverage_note']) ?>
    </div>
<?php endif ?>
<?php if ((int) ($scan['declared_gaps'] ?? 0) > 0) : ?>
    <?php
    /*
     * L'inventaire s'annonce complet parce qu'une liste a ete declaree, mais
     * le serveur lui-meme la contredit : il montre des bases qu'elle ignore.
     * Sans ce rappel, on lirait « 44 orphelines » comme un total, alors que
     * c'est un minimum.
     */
    ?>
    <div class="notice warning">
        <strong>Ta liste de bases est incomplète</strong>
        MySQL a trouvé <?= (int) $scan['declared_gaps'] ?> base(s) qui n'y figurent pas — le tableau de hPanel
        se pagine, et le collage s'est probablement arrêté au premier écran. Les orphelines
        ci-dessous sont réelles, mais il en manque peut-être d'autres. Recopie le tableau
        entier, puis <code>php bin/hspace import-databases</code> et <code>php bin/hspace scan</code>.
    </div>
<?php endif ?>

<div class="grid grid-kpi">
    <a class="card kpi" href="<?= Fmt::url('/sites') ?>">
        <div class="value"><?= Fmt::e(Fmt::number($scan['site_count'])) ?></div>
        <div class="label">Sites</div>
        <div class="hint"><?= Fmt::e(Fmt::bytes($scan['disk_bytes'])) ?> sur le disque</div>
    </a>

    <a class="card kpi" href="<?= Fmt::url('/databases') ?>">
        <div class="value"><?= Fmt::e(Fmt::number($scan['database_count'])) ?></div>
        <div class="label">Bases de données</div>
        <div class="hint"><?= (int) $scan['database_bytes'] === 0 && (int) $scan['database_count'] > 0
            ? 'taille inconnue — aucune n’a pu être ouverte'
            : Fmt::e(Fmt::bytes($scan['database_bytes'])) . ' au total' ?></div>
    </a>

    <?php
    /*
     * Sans vue complete du serveur MySQL, l'outil ne voit que des bases deja
     * utilisees : il ne peut donc structurellement pas en trouver une seule
     * d'inutilisee. Afficher « 0 » se lirait « aucune n'existe » alors que
     * cela veut dire « je ne peux pas savoir » — on affiche donc le doute.
     */
    $orphansKnown = (int) $scan['coverage_complete'] === 1 || (int) $scan['orphan_count'] > 0;
    ?>
    <a class="card kpi <?= !$orphansKnown ? '' : ((int) $scan['orphan_count'] > 0 ? 'is-warning' : 'is-ok') ?>" href="<?= Fmt::url('/orphans') ?>">
        <div class="value"><?= $orphansKnown ? Fmt::e(Fmt::number($scan['orphan_count'])) : '?' ?></div>
        <div class="label">Bases orphelines</div>
        <div class="hint"><?php
            /*
             * « Aucune base inutilisée » ne doit sortir que si le compte est
             * bien zero. Avec 43 orphelines jamais ouvertes, la somme des
             * tailles vaut zero elle aussi — et la carte affichait alors « 43 »
             * au-dessus de « aucune base inutilisée ».
             */
            if (!$orphansKnown) {
                echo 'indéterminé — accès MySQL partiel';
            } elseif ((int) $scan['orphan_count'] === 0) {
                echo 'aucune base inutilisée';
            } elseif ($orphanBytes > 0) {
                echo Fmt::e(Fmt::bytes($orphanBytes)), ' récupérables';
            } else {
                echo 'taille inconnue — jamais ouvertes';
            }
        ?></div>
    </a>

    <a class="card kpi <?= $counts['critical'] > 0 ? 'is-critical' : 'is-ok' ?>" href="<?= Fmt::url('/findings?severity=critical') ?>">
        <div class="value"><?= Fmt::e(Fmt::number($counts['critical'])) ?></div>
        <div class="label">Points critiques</div>
        <div class="hint"><?= Fmt::e(Fmt::number($counts['warning'])) ?> autres à vérifier</div>
    </a>
</div>

<div class="grid grid-2">
    <section class="card">
        <h2>À regarder en premier</h2>

        <?php if ($findings === []) : ?>
            <p class="muted">Aucun constat : rien ne réclame ton attention sur ce relevé.</p>
        <?php else : ?>
            <?php foreach ($findings as $finding) : ?>
                <?= hs_finding($finding, withActions: false) ?>
            <?php endforeach ?>

            <p><a href="<?= Fmt::url('/findings') ?>">Voir tous les constats →</a></p>
        <?php endif ?>
    </section>

    <div>
        <section class="card">
            <h2>Bases les plus lourdes</h2>
            <?php if ($measuredDatabases === []) : ?>
                <?php
                /*
                 * Un classement par taille n'a aucun sens quand aucune taille
                 * n'a ete mesuree : il afficherait cinq bases au hasard, toutes
                 * a « 0 o », et donnerait l'impression d'un espace vide.
                 */
                ?>
                <p class="muted">
                    Aucune base n’a pu être ouverte : les tailles sont inconnues.
                    Le classement demande un accès MySQL à ces bases.
                </p>
            <?php else : ?>
            <div class="table-wrap">
            <table>
                <tbody>
                <?php foreach ($measuredDatabases as $database) : ?>
                    <tr>
                        <td>
                            <a href="<?= Fmt::databaseUrl((string) $database['name']) ?>"><?= Fmt::e((string) $database['name']) ?></a>
                            <?php if ((int) $database['is_orphan'] === 1) : ?>
                                <span class="badge warning">orpheline</span>
                            <?php endif ?>
                        </td>
                        <td><?= hs_bar((int) $database['size_bytes'], (int) $maxDatabase, (int) $database['is_orphan'] === 1) ?></td>
                        <td class="num"><?= Fmt::e(Fmt::bytes($database['size_bytes'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
            <?php endif ?>
        </section>

        <section class="card">
            <h2>Sites les plus lourds</h2>
            <div class="table-wrap">
            <table>
                <tbody>
                <?php foreach ($biggestSites as $site) : ?>
                    <tr>
                        <td><?= Fmt::e(hs_site_name($site)) ?></td>
                        <td class="muted nowrap"><?= Fmt::e((string) $site['app_label']) ?></td>
                        <td><?= hs_bar((int) $site['size_bytes'], (int) $maxSite) ?></td>
                        <td class="num"><?= Fmt::e(Fmt::bytes($site['size_bytes'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </section>

        <section class="card">
            <h2>Sites les plus figés</h2>
            <p class="subtitle">Date du fichier le plus récent, caches exclus.</p>
            <div class="table-wrap">
            <table>
                <tbody>
                <?php foreach ($stale as $site) : ?>
                    <tr>
                        <td><?= Fmt::e(hs_site_name($site)) ?></td>
                        <td class="muted nowrap"><?= Fmt::e((string) $site['app_label']) ?></td>
                        <td class="num muted"><?= Fmt::e(Fmt::since($site['last_modified_at'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </section>
    </div>
</div>
