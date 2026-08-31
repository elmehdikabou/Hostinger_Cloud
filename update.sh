#!/bin/sh
#
# Mise a jour de hspace sur un hebergement Hostinger.
#
# A lancer depuis la racine du projet, en SSH sur l'hebergement :
#
#     sh update.sh
#
# Recupere la derniere version, met le schema a jour si besoin, et relance un
# releve. Rien n'est perdu : ni la configuration, ni le mot de passe de
# l'interface, ni l'historique des releves.
#
# La liste de bases declaree depuis hPanel (config/databases.txt) survit elle
# aussi : elle est ignoree par git, donc jamais ecrasee par une mise a jour.

set -e

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$(printf '\033[1m'); DIM=$(printf '\033[2m')
    GREEN=$(printf '\033[32m'); RED=$(printf '\033[31m'); RESET=$(printf '\033[0m')
else
    BOLD=''; DIM=''; GREEN=''; RED=''; RESET=''
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s▸ %s%s\n' "$BOLD" "$*" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
bad()  { printf '  %s✗%s %s\n' "$RED" "$RESET" "$*"; }
dim()  { printf '  %s%s%s\n' "$DIM" "$*" "$RESET"; }
fail() { bad "$*"; exit 1; }

cd "$(dirname "$0")"

[ -f bin/hspace ] || fail 'Lance ce script depuis la racine du projet hspace.'
[ -f config/config.php ] || fail 'Aucune configuration : lance « sh deploy.sh » plutot.'

# --- PHP -------------------------------------------------------------------
# Sur un mutualise, « php » pointe souvent vers une version ancienne alors que
# des versions recentes sont installees a cote.

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

# --- Code ------------------------------------------------------------------

step 'Recuperation de la derniere version'

AVANT=$(git rev-parse --short HEAD 2>/dev/null || echo '?')

BRANCHE=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')
[ -n "$BRANCHE" ] || fail "Ce dossier n'est pas un depot git."

# Un « git pull » qui echoue laisserait tourner le reste du script sur du code
# ancien, en donnant l'impression d'une mise a jour reussie.
git pull origin "$BRANCHE" || fail 'La recuperation a echoue (voir ci-dessus). Rien n a ete modifie.'

APRES=$(git rev-parse --short HEAD)

if [ "$AVANT" = "$APRES" ]; then
    ok "Deja a jour ($APRES)"
else
    ok "$AVANT → $APRES"
fi

# --- Dependances -----------------------------------------------------------
# Seulement si composer.lock a bouge : sur un mutualise, un composer install
# inutile coute une minute pour rien.

if [ -f composer.json ] && [ "$AVANT" != "$APRES" ] \
   && ! git diff --quiet "$AVANT" "$APRES" -- composer.lock 2>/dev/null
then
    step 'Mise a jour des dependances'

    if [ -f composer.phar ]; then
        "$PHP" composer.phar install --no-dev --no-interaction --quiet && ok 'dependances a jour'
    elif command -v composer >/dev/null 2>&1; then
        composer install --no-dev --no-interaction --quiet && ok 'dependances a jour'
    else
        dim 'composer introuvable — dependances inchangees.'
    fi
fi

# --- Releve ----------------------------------------------------------------
# Le schema de la base evolue avec le code : il est migre a l'ouverture, donc
# au lancement du scan. C'est aussi lui qui reecrit ce que l'interface montre —
# sans releve, une mise a jour ne change rien a l'ecran.

step 'Nouveau releve'

"$PHP" bin/hspace scan

say ''
dim 'Recharge l interface pour voir ce releve.'
say ''
