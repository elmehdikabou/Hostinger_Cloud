<?php

use HostingerSpace\Web\Fmt;

require_once __DIR__ . '/_partials.php';

/**
 * @var array<int,array<string,mixed>> $orphans
 * @var array<string,mixed>            $scan
 */

/*
 * Une base connue par la seule liste hPanel n'a jamais ete ouverte : ses zero
 * table et zero octet sont l'absence de mesure, pas un constat de vacuite. Les
 * compter avec les autres afficherait « 43 bases totalement vides, les plus
 * sures a supprimer » a propos de bases dont nul ne sait ce qu'elles
 * contiennent. On les met donc a part partout.
 */
$measured = array_values(array_filter($orphans, Fmt::measured(...)));
$total = array_sum(array_map(static fn (array $d): int => (int) $d['size_bytes'], $measured));
$empty = count(array_filter($measured, static fn (array $d): bool => (int) $d['table_count'] === 0));
$unknown = count($orphans) - count($measured);
$max = 1;

foreach ($measured as $orphan) {
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

<?php if ($orphans === [] && (int) $scan['coverage_complete'] !== 1) : ?>
    <?php
    /*
     * Le cas le plus trompeur de tout l'outil. Sans acces MySQL global, les
     * seules bases visibles sont celles rattachees aux comptes lus dans les
     * sites — donc des bases utilisees. Zero orpheline n'y est pas un
     * resultat : c'est l'unique resultat possible. Annoncer « aucune » serait
     * mensonger.
     */
    ?>
    <section class="card">
        <div class="empty-state">
            <h2>Impossible à déterminer</h2>
            <p>Ce n'est pas qu'il n'y en a aucune : l'outil ne peut pas le savoir dans l'état actuel.</p>
        </div>

        <div class="notice warning">
            <strong>Pourquoi</strong>
            Un utilisateur MySQL ne voit que les bases auxquelles il est rattaché. Faute d'accès
            global, seuls les comptes lus dans tes sites ont été interrogés — donc uniquement des
            bases déjà utilisées. Une base qu'aucun site n'utilise reste invisible, et c'est
            précisément celle qu'on cherche.
        </div>

        <h2>Obtenir la vue complète</h2>
        <ol class="actions">
            <li>hPanel &gt; Bases de données MySQL &gt; crée un utilisateur, puis rattache-le à
                <strong>toutes</strong> tes bases.</li>
            <li>Renseigne-le dans <code>config/config.php</code>, section <code>mysql</code> :
                <code class="command">'admin_user' =&gt; 'uXXXXXXXXX_inventaire',
'admin_password' =&gt; '…',</code>
            </li>
            <li>Relance le relevé :
                <code class="command">php bin/hspace scan</code>
            </li>
        </ol>
    </section>
<?php elseif ($orphans === []) : ?>
    <section class="card">
        <div class="empty-state">
            <h2>Aucune base orpheline</h2>
            <p>Chaque base du serveur est utilisée par au moins un site. Rien à nettoyer de ce côté.</p>
        </div>
    </section>
<?php else : ?>
    <div class="grid grid-kpi">
        <div class="card kpi is-warning">
            <div class="value"><?= count($orphans) ?></div>
            <div class="label">Bases sans site</div>
        </div>
        <div class="card kpi">
            <div class="value"><?= $measured === [] ? '?' : Fmt::e(Fmt::bytes($total)) ?></div>
            <div class="label">Espace concerné</div>
            <div class="hint"><?= $measured === []
                ? 'aucune de ces bases n’a pu être ouverte'
                : 'récupérable après vérification' ?></div>
        </div>
        <?php if ($unknown > 0) : ?>
            <div class="card kpi">
                <div class="value"><?= $unknown ?></div>
                <div class="label">Contenu inconnu</div>
                <div class="hint">jamais ouvertes — à sauvegarder avant tout</div>
            </div>
        <?php else : ?>
            <div class="card kpi">
                <div class="value"><?= $empty ?></div>
                <div class="label">Dont totalement vides</div>
                <div class="hint">les plus sûres à supprimer</div>
            </div>
        <?php endif ?>
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
                            <?php if (!Fmt::measured($orphan)) : ?>
                                <span class="badge neutral">non ouverte</span>
                            <?php elseif ((int) $orphan['table_count'] === 0) : ?>
                                <span class="badge neutral">vide</span>
                            <?php endif ?>
                        </td>
                        <td class="num"><?= Fmt::e(Fmt::measuredValue($orphan, Fmt::number($orphan['table_count']))) ?></td>
                        <td><?= Fmt::measured($orphan) ? hs_bar((int) $orphan['size_bytes'], $max, true) : '' ?></td>
                        <td class="num"><?= Fmt::e(Fmt::measuredValue($orphan, Fmt::bytes($orphan['size_bytes']))) ?></td>
                        <td class="num muted"><?= Fmt::e(Fmt::measuredValue($orphan, Fmt::date($orphan['created_at']))) ?></td>
                        <td class="num muted"><?= Fmt::e(Fmt::measuredValue($orphan, Fmt::since($orphan['updated_at']))) ?></td>
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
