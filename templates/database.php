<?php

use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<string,mixed>            $database
 * @var array<int,array<string,mixed>> $links
 * @var array<int,array<string,mixed>> $findings
 * @var array<int,array<string,mixed>> $history
 */

$name = (string) $database['name'];
$isOrphan = (int) $database['is_orphan'] === 1;
$sources = ScanRepository::decode($database['discovered_via']);

?>
<div class="page-head">
    <div>
        <h1 class="mono"><?= Fmt::e($name) ?></h1>
        <p class="subtitle">
            <?php if ($isOrphan) : ?>
                <span class="badge warning">aucun site ne l'utilise</span>
            <?php else : ?>
                <span class="badge ok">rattachée à <?= count($links) ?> site(s)</span>
            <?php endif ?>
        </p>
    </div>
    <div><a href="<?= Fmt::url('/databases') ?>">← Toutes les bases</a></div>
</div>

<div class="grid grid-2">
    <section class="card">
        <h2>Caractéristiques</h2>
        <dl class="pairs">
            <dt>Taille</dt>
            <dd><?= Fmt::e(Fmt::bytes($database['size_bytes'])) ?></dd>

            <dt>Tables</dt>
            <dd><?= Fmt::e(Fmt::number($database['table_count'])) ?></dd>

            <dt>Lignes estimées</dt>
            <dd><?= Fmt::e(Fmt::number($database['row_estimate'])) ?></dd>

            <dt>Créée le</dt>
            <dd><?= Fmt::e(Fmt::date($database['created_at'])) ?></dd>

            <dt>Dernière écriture</dt>
            <dd><?= Fmt::e(Fmt::since($database['updated_at'])) ?> <span class="muted">(<?= Fmt::e(Fmt::date($database['updated_at'])) ?>)</span></dd>

            <dt>Jeu de caractères</dt>
            <dd class="mono"><?= Fmt::e((string) ($database['charset'] ?? '—')) ?></dd>

            <dt>Vue par</dt>
            <dd><?= $sources === [] ? '—' : Fmt::e(implode(', ', array_map(strval(...), $sources))) ?></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Sites qui la déclarent</h2>

        <?php if ($links === []) : ?>
            <p class="muted">Aucun site analysé ne référence cette base.</p>
        <?php else : ?>
            <table>
                <tbody>
                <?php foreach ($links as $link) : ?>
                    <tr>
                        <td>
                            <a href="<?= Fmt::siteUrl((string) $link['site_key']) ?>">
                                <?= Fmt::e((string) ($link['domain'] ?? $link['site_key'])) ?><?= $link['mount_path'] !== null && $link['mount_path'] !== '/' ? Fmt::e((string) $link['mount_path']) : '' ?>
                            </a>
                            <div class="muted mono"><?= Fmt::e((string) ($link['source_file'] ?? '')) ?></div>
                        </td>
                        <td class="nowrap"><?= Fmt::e((string) ($link['app_label'] ?? '')) ?></td>
                        <td class="mono"><?= Fmt::e((string) ($link['table_prefix'] ?? '—')) ?></td>
                        <td><span class="badge <?= Fmt::e(Fmt::stateClass((string) $link['state'])) ?>"><?= Fmt::e(Fmt::stateLabel((string) $link['state'])) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>

<?php if ($isOrphan) : ?>
    <section class="card">
        <h2>Avant de la supprimer</h2>
        <div class="notice warning">
            <strong>hspace ne supprime jamais rien</strong>
            L'outil signale, il n'agit pas. Voici la marche à suivre, à exécuter toi-même.
        </div>

        <ol>
            <li>
                Sauvegarder la base, et vérifier que le fichier obtenu n'est pas vide :
                <code class="command">mysqldump -u UTILISATEUR -p <?= Fmt::e($name) ?> &gt; ~/sauvegarde-<?= Fmt::e($name) ?>.sql</code>
            </li>
            <li>
                Chercher son nom ailleurs dans tes fichiers : un script hors site, une tâche planifiée
                ou un connecteur peut l'utiliser sans qu'aucun site ne la déclare.
                <code class="command">grep -rl <?= Fmt::e($name) ?> ~/domains ~/public_html 2&gt;/dev/null</code>
            </li>
            <li>Attendre quelques jours après le dernier accès, par prudence.</li>
            <li>Supprimer depuis <strong>hPanel &gt; Bases de données MySQL</strong>.</li>
        </ol>
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

<?php if (count($history) > 1) : ?>
    <section class="card">
        <h2>Évolution</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Relevé</th>
                    <th>Date</th>
                    <th class="num">Tables</th>
                    <th class="num">Taille</th>
                    <th>État</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $entry) : ?>
                    <tr>
                        <td>#<?= (int) $entry['id'] ?></td>
                        <td><?= Fmt::e(Fmt::dateTime($entry['finished_at'])) ?></td>
                        <td class="num"><?= Fmt::e(Fmt::number($entry['table_count'])) ?></td>
                        <td class="num"><?= Fmt::e(Fmt::bytes($entry['size_bytes'])) ?></td>
                        <td>
                            <?php if ((int) $entry['is_orphan'] === 1) : ?>
                                <span class="badge warning">orpheline</span>
                            <?php else : ?>
                                <span class="badge ok">utilisée</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif ?>
