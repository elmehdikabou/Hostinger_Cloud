#!/bin/sh
#
# Installation de hspace sur un hebergement Hostinger.
#
# A lancer depuis la racine du projet, en SSH sur l'hebergement :
#
#     sh deploy.sh
#
# Le script est idempotent : le relancer met a jour l'installation sans rien
# perdre de la configuration ni de l'historique des releves.

set -eu

PROJECT_DIR=$(cd "$(dirname "$0")" && pwd)
cd "$PROJECT_DIR"

RED=''
GREEN=''
DIM=''
BOLD=''
RESET=''

if [ -t 1 ]; then
    RED=$(printf '\033[31m')
    GREEN=$(printf '\033[32m')
    DIM=$(printf '\033[2m')
    BOLD=$(printf '\033[1m')
    RESET=$(printf '\033[0m')
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s▸ %s%s\n' "$BOLD" "$*" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
bad()  { printf '  %s✗%s %s\n' "$RED" "$RESET" "$*"; }
dim()  { printf '  %s%s%s\n' "$DIM" "$*" "$RESET"; }

fail() { bad "$*"; exit 1; }

# ---------------------------------------------------------------------------
# 1. Trouver un PHP assez recent
#
# Sur un mutualise, « php » pointe souvent vers une version ancienne alors que
# des versions recentes sont installees a cote. On les cherche explicitement
# plutot que de renvoyer l'utilisateur vers hPanel pour rien.
# ---------------------------------------------------------------------------

step 'Recherche de PHP 8.2 ou plus recent'

PHP=''

for candidate in \
    php8.4 php8.3 php8.2 php \
    /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 /usr/bin/php \
    /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php
do
    path=$(command -v "$candidate" 2>/dev/null) || continue

    if "$path" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
        PHP="$path"
        break
    fi
done

[ -n "$PHP" ] || fail 'Aucun PHP 8.2+ trouve. Change la version dans hPanel > Avance > Configuration PHP.'

ok "$PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

for extension in pdo_sqlite mbstring; do
    if "$PHP" -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
        ok "extension $extension"
    else
        fail "Extension $extension absente. Active-la dans hPanel > Avance > Configuration PHP."
    fi
done

# ---------------------------------------------------------------------------
# 2. Dependances
# ---------------------------------------------------------------------------

step 'Installation des dependances'

COMPOSER=''

if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
elif [ -f composer.phar ]; then
    COMPOSER="$PHP composer.phar"
else
    dim 'composer absent, telechargement de composer.phar…'

    if command -v curl >/dev/null 2>&1; then
        curl -sS -o composer-setup.php https://getcomposer.org/installer
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O composer-setup.php https://getcomposer.org/installer
    else
        fail 'Ni curl ni wget : impossible de recuperer composer.'
    fi

    "$PHP" composer-setup.php --quiet
    rm -f composer-setup.php
    COMPOSER="$PHP composer.phar"
fi

# shellcheck disable=SC2086
$COMPOSER install --no-dev --no-interaction --quiet || fail 'composer install a echoue.'
ok 'dependances installees'

# ---------------------------------------------------------------------------
# 3. Configuration
# ---------------------------------------------------------------------------

step 'Configuration'

if [ -f config/config.php ]; then
    ok 'config/config.php existe deja, il est conserve'
else
    "$PHP" bin/hspace init --local --no-interactive >/dev/null
    ok 'config/config.php cree en mode local'
    dim 'Renseigne « mysql.admin_user » pour une detection fiable des orphelines.'
fi

chmod 600 config/config.php 2>/dev/null || true
mkdir -p var
chmod 700 var 2>/dev/null || true

# ---------------------------------------------------------------------------
# 4. Mot de passe de l'interface
#
# Sans lui, l'interface refuse d'afficher quoi que ce soit depuis l'exterieur.
# C'est voulu : elle publie la carte de l'hebergement.
# ---------------------------------------------------------------------------

NEEDS_PASSWORD=0

step "Mot de passe de l'interface"

if "$PHP" -r '$c = require "config/config.php"; exit(empty($c["web"]["password_hash"]) ? 1 : 0);'; then
    ok 'un mot de passe est deja defini'
else
    # Un echec ici n'interrompt pas l'installation : l'interface refuse de
    # toute facon d'afficher quoi que ce soit sans mot de passe, il n'y a donc
    # rien d'expose. Mieux vaut une installation complete a securiser qu'une
    # installation a moitie faite.
    if [ -t 0 ]; then
        "$PHP" bin/hspace passwd || NEEDS_PASSWORD=1
    else
        "$PHP" bin/hspace passwd --generate || NEEDS_PASSWORD=1
    fi
fi

# ---------------------------------------------------------------------------
# 5. Verification et premier releve
# ---------------------------------------------------------------------------

step 'Diagnostic'
"$PHP" bin/hspace doctor || dim 'Le diagnostic signale des points a corriger (voir ci-dessus).'

step 'Premier releve'
"$PHP" bin/hspace scan

# ---------------------------------------------------------------------------
# 6. Verification de l'exposition web
#
# Le projet peut se retrouver sous public_html : on verifie alors que les
# dossiers sensibles sont bien refuses, plutot que de le supposer.
# ---------------------------------------------------------------------------

step 'Exposition web'

say ''
dim "Racine du projet : $PROJECT_DIR"
dim "Racine web attendue : $PROJECT_DIR/public"

# Quand on cree un sous-domaine, Hostinger depose une page d'attente dans le
# dossier choisi. Un index.html restant a cote de index.php passe avant lui
# dans l'ordre des pages d'accueil : le serveur continuerait a afficher la
# page par defaut, et on chercherait longtemps pourquoi.
for leftover in public/index.html public/index.htm public/default.html; do
    if [ -f "$leftover" ]; then
        mv "$leftover" "$leftover.remplace-par-hspace"
        ok "$(basename "$leftover") de Hostinger mis de cote : il masquait index.php"
    fi
done

for guarded in config var; do
    if [ -f "$guarded/.htaccess" ]; then
        ok "$guarded/ protege par son propre .htaccess"
    else
        bad "$guarded/.htaccess manquant — a verifier avant d'exposer le site."
    fi
done

case "$PROJECT_DIR" in
    */public_html/*)
        say ''
        dim 'Le projet est sous public_html : il est donc aussi atteignable par le'
        dim 'domaine parent. Verifie depuis un navigateur que ces deux adresses'
        dim 'renvoient bien une erreur, et non un fichier :'
        say ''
        dim '   <ton-domaine>/hspace/config/config.php'
        dim '   <ton-domaine>/hspace/var/inventory.sqlite'
        ;;
esac

step 'Termine'
say ''

if [ "$NEEDS_PASSWORD" = '1' ]; then
    bad "Aucun mot de passe defini : l'interface refusera d'afficher les donnees."
    dim "Lance : $PHP bin/hspace passwd --generate"
    say ''
fi

say "  Ouvre ton sous-domaine dans un navigateur : la page de connexion"
say "  doit apparaitre. Si tu vois les donnees sans mot de passe, arrete"
say "  tout et lance : $PHP bin/hspace passwd"
say ''
say "  Releve automatique chaque lundi — hPanel > Avance > Taches Cron :"
say ""
say "     0 5 * * 1 cd $PROJECT_DIR && $PHP bin/hspace scan >> var/scan.log 2>&1"
say ''
