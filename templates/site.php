<?php

use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<string,mixed>            $site
 * @var array<int,array<string,mixed>> $links
 * @var array<int,array<string,mixed>> $findings
 */

$notes = ScanRepository::decode($site['notes']);
[$sslLabel, $sslClass] = Fmt::expiry($site['ssl_expires_at'], 21, 7);
[$domainLabel, $domainClass] = Fmt::expiry($site['domain_expires_at'], 45, 14);

?>
<div class="page-head">
    <div>
        <h1><?= Fmt::e(hs_site_name($site)) ?></h1>
        <p class="subtitle"><?= hs_app_tag((string) $site['app'], (string) $site['app_label'], $site['version']) ?></p>
    </div>
    <div><a href="<?= Fmt::url('/sites') ?>">← Tous les sites</a></div>
</div>

<div class="grid grid-2">
    <section class="card">
        <h2>Fiche</h2>
        <dl class="pairs">
            <dt>Chemin</dt>
            <dd class="mono"><?= Fmt::e((string) $site['path']) ?></dd>

            <dt>Domaine</dt>
            <dd><?= Fmt::e((string) $site['domain']) ?></dd>

            <?php if ($site['parent_key'] !== null) : ?>
                <dt>Rattaché à</dt>
                <dd><a href="<?= Fmt::siteUrl((string) $site['parent_key']) ?>"><?= Fmt::e((string) $site['parent_key']) ?></a></dd>
            <?php endif ?>

            <dt>Identifié par</dt>
            <dd class="mono"><?= Fmt::e((string) ($site['evidence'] ?? '—')) ?></dd>

            <dt>Taille</dt>
            <dd><?= Fmt::e(Fmt::bytes($site['size_bytes'])) ?> — <?= Fmt::e(Fmt::number($site['file_count'])) ?> fichiers</dd>

            <dt>Dernière activité</dt>
            <dd><?= Fmt::e(Fmt::since($site['last_modified_at'])) ?> <span class="muted">(<?= Fmt::e(Fmt::date($site['last_modified_at'])) ?>)</span></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Vu depuis l'extérieur</h2>
        <dl class="pairs">
            <dt>Réponse HTTP</dt>
            <dd>
                <?php if ($site['http_status'] === null) : ?>
                    <span class="muted">non vérifiée</span>
                <?php else : ?>
                    <span class="badge <?= Fmt::e(Fmt::httpClass($site['http_status'])) ?>"><?= (int) $site['http_status'] ?></span>
                <?php endif ?>
            </dd>

            <?php if ($site['http_note'] !== null) : ?>
                <dt>Observation</dt>
                <dd><?= Fmt::e((string) $site['http_note']) ?></dd>
            <?php endif ?>

            <?php if ($site['final_url'] !== null) : ?>
                <dt>Adresse finale</dt>
                <dd class="mono"><?= Fmt::e((string) $site['final_url']) ?></dd>
            <?php endif ?>

            <dt>Certificat SSL</dt>
            <dd>
                <span class="badge <?= Fmt::e($sslClass) ?>"><?= Fmt::e($sslLabel) ?></span>
                <?php if ($site['ssl_issuer'] !== null) : ?>
                    <span class="muted"><?= Fmt::e((string) $site['ssl_issuer']) ?></span>
                <?php endif ?>
            </dd>

            <dt>Nom de domaine</dt>
            <dd>
                <span class="badge <?= Fmt::e($domainClass) ?>"><?= Fmt::e($domainLabel) ?></span>
                <?php if ($site['registrar'] !== null) : ?>
                    <span class="muted"><?= Fmt::e((string) $site['registrar']) ?></span>
                <?php endif ?>
            </dd>
        </dl>
    </section>
</div>

<section class="card">
    <h2>Bases de données déclarées</h2>

    <?php if ($links === []) : ?>
        <p class="muted">Aucune base n'a pu être lue dans la configuration de ce site.</p>
    <?php else : ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Base</th>
                    <th>État</th>
                    <th>Utilisateur</th>
                    <th>Hôte</th>
                    <th>Préfixe</th>
                    <th>Lue dans</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($links as $link) : ?>
                    <tr>
                        <td><a href="<?= Fmt::databaseUrl((string) $link['database_name']) ?>" class="mono"><?= Fmt::e((string) $link['database_name']) ?></a></td>
                        <td><span class="badge <?= Fmt::e(Fmt::stateClass((string) $link['state'])) ?>"><?= Fmt::e(Fmt::stateLabel((string) $link['state'])) ?></span></td>
                        <td class="mono"><?= Fmt::e((string) ($link['db_user'] ?? '—')) ?></td>
                        <td class="mono"><?= Fmt::e((string) ($link['db_host'] ?? '—')) ?></td>
                        <td class="mono"><?= Fmt::e((string) ($link['table_prefix'] ?? '—')) ?></td>
                        <td class="mono muted"><?= Fmt::e((string) ($link['source_file'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Les mots de passe ne sont volontairement pas conservés.</p>
    <?php endif ?>
</section>

<?php if ($notes !== []) : ?>
    <section class="card">
        <h2>Observations du scan</h2>
        <ul class="actions">
            <?php foreach ($notes as $note) : ?>
                <li><?= Fmt::e((string) $note) ?></li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<?php if ($findings !== []) : ?>
    <section class="card">
        <h2>Constats</h2>
        <?php foreach ($findings as $finding) : ?>
            <?= hs_finding($finding) ?>
        <?php endforeach ?>
    </section>
<?php endif ?>
