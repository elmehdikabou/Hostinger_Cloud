<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<int,array<string,mixed>> $orphans
 * @var array<string,mixed>            $scan
 */

$total = array_sum(array_map(static fn (array $d): int => (int) $d['size_bytes'], $orphans));
$max = 1;

foreach ($orphans as $orphan) {
    $max = max($max, (int) $orphan['size_bytes']);
}

?>
<div class="page-head">
    <div>
        <h1>Bases orphelines</h1>
        <p class="subtitle">Bases présentes sur le serveur MySQL qu'aucun site analysé ne référence.</p>
    </div>
</div>

<?php if ((int) $scan['coverage_complete'] !== 1) : ?>
    <div class="notice warning">
        <strong>Cette liste peut contenir des faux positifs</strong>
        <?= Fmt::e((string) $scan['coverage_note']) ?>
    </div>
<?php endif ?>

<?php if ($orphans === []) : ?>
    <section class="card">
        <div class="empty-state">
            <h2>Aucune base orpheline</h2>
            <p>Chaque base visible est utilisée par au moins un site. Rien à nettoyer de ce côté.</p>
        </div>
    </section>
<?php else : ?>
    <div class="grid grid-kpi">
        <div class="card kpi is-warning">
            <div class="value"><?= count($orphans) ?></div>
            <div class="label">Bases sans site</div>
        </div>
        <div class="card kpi">
            <div class="value"><?= Fmt::e(Fmt::bytes($total)) ?></div>
            <div class="label">Espace concerné</div>
            <div class="hint">récupérable après vérification</div>
        </div>
        <div class="card kpi">
            <div class="value"><?= count(array_filter($orphans, static fn (array $d): bool => (int) $d['table_count'] === 0)) ?></div>
            <div class="label">Dont totalement vides</div>
            <div class="hint">les plus sûres à supprimer</div>
        </div>
    </div>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Base</th>
                    <th class="num">Tables</th>
                    <th></th>
                    <th class="num">Taille</th>
                    <th class="num">Créée</th>
                    <th class="num">Dernière écriture</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orphans as $orphan) : ?>
                    <tr>
                        <td>
                            <a href="<?= Fmt::databaseUrl((string) $orphan['name']) ?>" class="mono"><?= Fmt::e((string) $orphan['name']) ?></a>
                            <?php if ((int) $orphan['table_count'] === 0) : ?>
                                <span class="badge neutral">vide</span>
                            <?php endif ?>
                        </td>
                        <td class="num"><?= Fmt::e(Fmt::number($orphan['table_count'])) ?></td>
                        <td><?= hs_bar((int) $orphan['size_bytes'], $max, true) ?></td>
                        <td class="num"><?= Fmt::e(Fmt::bytes($orphan['size_bytes'])) ?></td>
                        <td class="num muted"><?= Fmt::e(Fmt::date($orphan['created_at'])) ?></td>
                        <td class="num muted"><?= Fmt::e(Fmt::since($orphan['updated_at'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Marche à suivre</h2>
        <div class="notice">
            <strong>hspace ne supprime jamais rien</strong>
            Une suppression déclenchée depuis une page web, sur la foi d'une heuristique, serait
            le pire défaut possible pour cet outil. Les commandes ci-dessous sont à lancer toi-même.
        </div>

        <p>Sauvegarder toutes les bases de la liste d'un coup :</p>
        <code class="command"><?php
            foreach ($orphans as $orphan) {
                echo 'mysqldump -u UTILISATEUR -p ' . Fmt::e((string) $orphan['name'])
                    . ' > ~/sauvegarde-' . Fmt::e((string) $orphan['name']) . ".sql\n";
            }
        ?></code>

        <p>Vérifier qu'aucun script ne les utilise en dehors des sites analysés :</p>
        <code class="command">grep -rlE '<?= Fmt::e(implode('|', array_map(static fn (array $d): string => (string) $d['name'], $orphans))) ?>' ~/domains ~/public_html 2>/dev/null</code>

        <p>Puis supprimer depuis <strong>hPanel &gt; Bases de données MySQL</strong>, une fois les sauvegardes vérifiées.</p>
    </section>
<?php endif ?>
