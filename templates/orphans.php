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
        <?= (int) $scan['declared_gaps'] ?> base(s) existent qui n'y figurent pas — vues par MySQL, ou
        déclarées par un de tes sites. Le tableau de hPanel
        se pagine, et le collage s'est probablement arrêté au premier écran. Les orphelines
        ci-dessous sont réelles, mais il en manque peut-être d'autres. Recopie le tableau
        entier, puis <code>php bin/hspace import-databases</code> et <code>php bin/hspace scan</code>.
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

        <h2>Obtenir la réponse</h2>
        <p class="subtitle">
            Décider qu'une base ne sert à personne ne demande pas de l'ouvrir : il suffit de
            connaître son nom. Copie le tableau de hPanel &gt; Bases de données MySQL, et la
            question devient décidable sans le moindre accès supplémentaire.
        </p>
        <ol class="actions">
            <li>Colle la liste, puis <kbd>Ctrl+D</kbd> :
                <code class="command">php bin/hspace import-databases</code>
            </li>
            <li>Relance le relevé :
                <code class="command">php bin/hspace scan</code>
            </li>
        </ol>

        <p class="subtitle">
            Les tailles resteront inconnues faute de pouvoir ouvrir ces bases — mais c'est le
            rattachement qui décide d'une orpheline, pas le poids. Si tu préfères la vue complète
            par MySQL, crée dans hPanel un utilisateur rattaché à <strong>toutes</strong> tes bases
            et renseigne <code>mysql.admin_user</code> dans <code>config/config.php</code>.
        </p>
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
        <?php
        /*
         * Deux populations de fiabilite opposee vivaient sous un seul chiffre.
         * Une base que MySQL a ouverte et qu'aucun site ne declare est une
         * orpheline etablie : on peut agir dessus. Un nom repris d'une liste
         * collee n'est qu'une piste — la base peut ne plus exister, ou etre
         * utilisee par un site que le scan n'a pas su lire.
         *
         * Les additionner donnait « 43 bases sans site » en gros et en tete,
         * soit le chiffre le moins sur de la page presente comme le plus sur.
         */
        ?>
        <div class="card kpi <?= $measured === [] ? '' : 'is-warning' ?>">
            <div class="value"><?= count($measured) ?></div>
            <div class="label">Orphelines vérifiées</div>
            <div class="hint">ouvertes par MySQL, utilisées par aucun site</div>
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
                <div class="label">Pistes à confirmer</div>
                <div class="hint">noms repris de la liste, jamais vérifiés</div>
            </div>
        <?php else : ?>
            <div class="card kpi">
                <div class="value"><?= $empty ?></div>
                <div class="label">Dont totalement vides</div>
                <div class="hint">les plus sûres à supprimer</div>
            </div>
        <?php endif ?>
    </div>

    <?php
    /*
     * Deux tableaux plutot qu'un. Melangees, 43 pistes non verifiees noyaient
     * les quelques orphelines etablies — les seules sur lesquelles on puisse
     * agir. L'ordre compte : ce qui est sur d'abord, ce qui reste a confirmer
     * ensuite, et jamais l'inverse.
     */
    ?>
    <section class="card">
        <h2>Orphelines vérifiées</h2>

        <?php if ($measured === []) : ?>
            <p class="muted">
                Aucune. Les bases que l'outil a pu ouvrir sont toutes utilisées par au moins un
                site — celles ci-dessous ne sont connues que par leur nom.
            </p>
        <?php else : ?>
        <p class="subtitle">
            Ouvertes par MySQL, donc bien réelles, et déclarées par aucun site.
        </p>
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
                <?php foreach ($measured as $orphan) : ?>
                    <tr>
                        <td>
                            <a href="<?= Fmt::databaseUrl((string) $orphan['name']) ?>" class="mono"><?= Fmt::e((string) $orphan['name']) ?></a>
                            <?php if (!Fmt::measured($orphan)) : ?>
                                <span class="badge neutral" title="Nom repris de la liste hPanel. Ni son contenu ni son existence n'ont été vérifiés.">non vérifiée</span>
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
        <?php endif ?>
    </section>

    <?php if ($unknown > 0) : ?>
        <section class="card">
            <h2>Pistes à confirmer</h2>
            <p class="subtitle">
                Ces noms viennent de la liste collée depuis hPanel, et de rien d'autre. Aucun accès
                MySQL ne les a atteints : leur existence n'est pas établie, et un site que le scan
                n'a pas su lire pourrait très bien s'en servir.
                <strong>Ne supprime rien d'ici sans l'avoir vérifié dans hPanel.</strong>
            </p>
            <div class="table-wrap">
                <table>
                    <tbody>
                    <?php foreach ($orphans as $orphan) : ?>
                        <?php if (Fmt::measured($orphan)) { continue; } ?>
                        <tr>
                            <td>
                                <a href="<?= Fmt::databaseUrl((string) $orphan['name']) ?>" class="mono"><?= Fmt::e((string) $orphan['name']) ?></a>
                            </td>
                            <td class="muted">nom déclaré, jamais vérifié</td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif ?>

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
