<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/** @var array<int,array<string,mixed>> $sites */

?>
<div class="page-head">
    <div>
        <h1>Domaines &amp; certificats</h1>
        <p class="subtitle">Ce que voit un visiteur, et les échéances à ne pas manquer.</p>
    </div>
</div>

<?php if ($sites === []) : ?>
    <section class="card">
        <div class="empty-state">
            <h2>Aucun domaine vérifié</h2>
            <p>Active « analysis.check_http », « analysis.check_ssl » et « analysis.check_domain_expiry »
                dans la configuration, puis relance un scan.</p>
        </div>
    </section>
<?php else : ?>
    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Domaine</th>
                    <th>HTTP</th>
                    <th>Observation</th>
                    <th>Certificat</th>
                    <th>Émetteur</th>
                    <th>Nom de domaine</th>
                    <th>Bureau d'enregistrement</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $site) : ?>
                    <?php
                    [$sslLabel, $sslClass] = Fmt::expiry($site['ssl_expires_at'], 21, 7);
                    [$domainLabel, $domainClass] = Fmt::expiry($site['domain_expires_at'], 45, 14);
                    ?>
                    <tr>
                        <td><a href="<?= Fmt::siteUrl((string) $site['site_key']) ?>"><?= Fmt::e((string) $site['domain']) ?></a></td>
                        <td>
                            <?php if ($site['http_status'] === null) : ?>
                                <span class="muted">—</span>
                            <?php else : ?>
                                <span class="badge <?= Fmt::e(Fmt::httpClass($site['http_status'])) ?>"><?= (int) $site['http_status'] ?></span>
                            <?php endif ?>
                        </td>
                        <td class="muted"><?= Fmt::e((string) ($site['http_note'] ?? $site['ssl_note'] ?? '')) ?></td>
                        <td><span class="badge <?= Fmt::e($sslClass) ?>"><?= Fmt::e($sslLabel) ?></span></td>
                        <td class="muted nowrap"><?= Fmt::e((string) ($site['ssl_issuer'] ?? '—')) ?></td>
                        <td><span class="badge <?= Fmt::e($domainClass) ?>"><?= Fmt::e($domainLabel) ?></span></td>
                        <td class="muted nowrap"><?= Fmt::e((string) ($site['registrar'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif ?>
