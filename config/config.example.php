<?php

/**
 * Copie ce fichier vers config/config.php puis remplis-le.
 * config/config.php est ignore par git : tes identifiants ne partiront jamais sur GitHub.
 *
 *     cp config/config.example.php config/config.php
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Connexion a l'hebergement
    |--------------------------------------------------------------------------
    |
    | mode = 'ssh'    : l'app tourne chez toi (ou ailleurs) et se connecte
    |                   a Hostinger en SSH. C'est le mode normal.
    | mode = 'local'  : l'app est deposee sur l'hebergement Hostinger lui-meme
    |                   et lit le systeme de fichiers en direct. Aucune section
    |                   'ssh' n'est alors necessaire.
    |
    | SSH doit etre active dans hPanel > Avance > Acces SSH (inclus dans les
    | offres Cloud et Business). Tu y trouveras l'hote, le port et l'utilisateur.
    |
    */
    'mode' => 'ssh',

    'ssh' => [
        'host' => '145.14.xxx.xxx',   // hPanel > Acces SSH > "IP SSH"
        'port' => 65002,              // Hostinger utilise rarement le port 22
        'username' => 'u123456789',   // ton identifiant uXXXXXXXXX

        // Choisis UNE methode d'authentification.
        // La cle privee est preferable : pas de mot de passe stocke en clair.
        'private_key_path' => null,   // ex: '/home/moi/.ssh/id_ed25519'
        'private_key_passphrase' => null,
        'password' => null,           // utilise seulement si private_key_path est null

        'timeout' => 30,

        /*
        | Empreinte de la cle publique du serveur (protection contre le
        | detournement de connexion). Laisse null au premier lancement :
        | `php bin/hspace doctor` affichera l'empreinte a coller ici.
        | Format : 'SHA256:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
        */
        'host_key_fingerprint' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ou chercher les sites
    |--------------------------------------------------------------------------
    |
    | Sur Hostinger, chaque domaine a son dossier dans ~/domains/<domaine>,
    | et le domaine principal pointe aussi vers ~/public_html.
    | Laisse la valeur par defaut sauf si ton arborescence est particuliere.
    |
    */
    'paths' => [
        'home' => '~',
        'domains_dir' => '~/domains',
        'extra_roots' => [
            // '~/public_html',
            // '~/projets/api-interne',
        ],
        // Dossiers ignores pendant l'analyse (bruit, poids, faux positifs).
        'ignore' => [
            'node_modules', 'vendor', '.git', 'cache', 'var/cache',
            'storage/framework', 'wp-content/cache', 'wp-content/uploads',
            '.wp-cli', 'backup', 'backups', '.trash',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Acces MySQL
    |--------------------------------------------------------------------------
    |
    | IMPORTANT - a lire, c'est ce qui determine la qualite de la detection
    | des bases orphelines.
    |
    | Sur un hebergement mutualise Hostinger, un utilisateur MySQL ne voit que
    | les bases auxquelles il a ete rattache. Un `SHOW DATABASES` avec le compte
    | d'un site ne montrera donc que la base de ce site.
    |
    | Pour obtenir la liste COMPLETE de tes bases (donc les orphelines), cree
    | dans hPanel > Bases de donnees MySQL un utilisateur rattache a TOUTES tes
    | bases, et renseigne-le ci-dessous. Sans lui, l'outil se rabat sur l'union
    | des acces trouves dans les sites : la couverture sera partielle, et il te
    | le dira explicitement dans le rapport.
    |
    */
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'admin_user' => null,       // ex: 'u123456789_inventaire'
        'admin_password' => null,

        // Utiliser aussi les identifiants trouves dans les sites pour elargir
        // la vue sur les bases (recommande, sans risque : lecture seule).
        'use_discovered_credentials' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyse
    |--------------------------------------------------------------------------
    */
    'analysis' => [
        // Un site sans ecriture fichier ni ecriture base depuis N jours est
        // signale comme potentiellement abandonne.
        'abandoned_after_days' => 180,

        // Profondeur max de recherche d'un site dans un dossier de domaine.
        'max_depth' => 3,

        // Verifier la reponse HTTP de chaque domaine (depuis la machine qui
        // lance le scan). Detecte les sites casses, parkes, en maintenance.
        'check_http' => true,

        // Lire la date d'expiration des certificats SSL (connexion TLS directe).
        'check_ssl' => true,

        // Interroger le RDAP pour la date d'expiration des noms de domaine.
        'check_domain_expiry' => true,

        // Delai reseau par verification, en secondes.
        'network_timeout' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stockage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'database' => __DIR__ . '/../var/inventory.sqlite',
    ],

    /*
    |--------------------------------------------------------------------------
    | Interface web
    |--------------------------------------------------------------------------
    */
    'web' => [
        'host' => '127.0.0.1',
        'port' => 8088,
    ],
];
