<?php

use HostingerSpace\Web\Fmt;

/**
 * Page de connexion. Volontairement autonome : tant que l'acces n'est pas
 * accorde, aucune donnee de l'inventaire n'est lue ni affichee — pas meme le
 * nombre de sites dans une barre laterale.
 *
 * @var string|null $error
 * @var string      $jeton
 */

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion — hspace</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<div class="gate">
    <div class="gate-card">
        <h1>hspace</h1>
        <p class="subtitle">Inventaire de l'hébergement. Accès protégé.</p>

        <?php if ($error !== null) : ?>
            <div class="notice critical"><?= Fmt::e($error) ?></div>
        <?php endif ?>

        <form method="post" action="/login" class="gate-form">
            <input type="hidden" name="jeton" value="<?= Fmt::e($jeton) ?>">

            <label for="motdepasse">Mot de passe</label>
            <input type="password" id="motdepasse" name="motdepasse"
                   autocomplete="current-password" autofocus required>

            <button type="submit">Se connecter</button>
        </form>
    </div>
</div>
</body>
</html>
