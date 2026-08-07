<?php

/**
 * Affiche quand la base d'inventaire existe mais ne contient aucun scan.
 * On garde la mise en page minimale : la barre laterale n'aurait rien a montrer.
 */

?>
<section class="card">
    <div class="empty-state">
        <h2>Aucun relevé pour l'instant</h2>
        <p>La base d'inventaire est vide. Lance un premier scan depuis le terminal :</p>
        <p><code class="command">php bin/hspace scan</code></p>
        <p>Ou, pour découvrir l'interface avec des données fictives :</p>
        <p><code class="command">php bin/hspace demo &amp;&amp; php bin/hspace serve --demo</code></p>
    </div>
</section>
