<?php

/**
 * Affichee quand l'interface est atteinte depuis l'exterieur alors qu'aucun
 * mot de passe n'est configure.
 *
 * On refuse d'afficher les donnees « en attendant » : l'inventaire contient
 * les noms des bases, les utilisateurs MySQL, les chemins sur le disque et la
 * liste des faiblesses de chaque site. Le laisser ouvert quelques heures, le
 * temps de s'en occuper, suffirait a le faire indexer ou moissonner.
 */

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Protection requise — hspace</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<div class="gate">
    <div class="gate-card gate-card--wide">
        <h1>Cette interface n'est pas protégée</h1>

        <p class="subtitle">
            Rien ne sera affiché tant qu'un mot de passe n'aura pas été configuré.
        </p>

        <div class="notice warning">
            <strong>Pourquoi ce refus</strong>
            L'inventaire liste les noms de tes bases, tes utilisateurs MySQL, les chemins
            sur le disque et les faiblesses repérées sur chaque site. Publié sans mot de
            passe, c'est un mode d'emploi pour attaquer ton hébergement.
        </div>

        <p>Depuis un terminal, sur le serveur où l'application est déposée :</p>

        <code class="command">php bin/hspace passwd</code>

        <p>
            La commande demande un mot de passe, en calcule le condensé et l'inscrit dans
            <code>config/config.php</code>. Le mot de passe lui-même n'est jamais stocké.
            Recharge ensuite cette page.
        </p>

        <p class="muted">
            Depuis la machine locale (<code>127.0.0.1</code>), l'interface reste accessible
            sans mot de passe : <code>php bin/hspace serve</code> s'adresse déjà à son
            propriétaire.
        </p>
    </div>
</div>
</body>
</html>
