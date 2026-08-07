# hspace — inventaire d'un espace Hostinger

Cartographie un hébergement Hostinger : quels sites existent, quelle base chacun
utilise, et **quelles bases ne servent plus à personne**.

Hostinger ne stocke nulle part le lien entre un site et sa base : ce lien vit
uniquement dans le fichier de configuration de chaque site. hspace le
reconstruit en lisant ces fichiers, le confronte à la liste réelle des bases du
serveur MySQL, et en tire trois réponses :

| | |
|---|---|
| **Bases orphelines** | Présentes en MySQL, référencées par aucun site. Candidates au ménage. |
| **Bases manquantes** | Un site pointe vers une base qui n'existe pas. Le site est cassé. |
| **Bases partagées** | Plusieurs sites sur la même base. À savoir avant d'y toucher. |

S'y ajoutent la technologie et la version de chaque site, les tailles, les
signes d'abandon, l'état HTTP réel de chaque domaine, l'expiration des
certificats SSL et des noms de domaine.

---

## Démarrage

### 1. Installer

```bash
git clone <ce-dépôt> hspace && cd hspace
composer install
```

Requiert PHP 8.2 ou plus, avec `pdo_sqlite`, `openssl`, `curl` et `mbstring`.

### 2. Voir à quoi ça ressemble, sans rien brancher

```bash
php bin/hspace demo          # fabrique un compte fictif et trois relevés
php bin/hspace serve --demo  # http://127.0.0.1:8088
```

Le compte fictif contient volontairement les cas tordus du terrain : un
`wp-config.php` rangé au-dessus de la racine web, un Laravel dont seul `public/`
est exposé, un vieux site branché en `mysqli_connect`, un Adminer oublié, deux
bases que plus personne n'utilise et une base déclarée mais absente du serveur.

### 3. Brancher ton hébergement

Deux voies possibles. Elles donnent le même résultat ; choisis selon ce qui
t'arrange.

#### A — depuis ta machine, par SSH

```bash
php bin/hspace init      # pose les questions une à une
php bin/hspace doctor    # vérifie tout et dit quoi corriger
php bin/hspace scan
php bin/hspace serve     # http://127.0.0.1:8088
```

`init` est guidé par défaut : il demande l'adresse, le port, l'identifiant, la
méthode d'authentification et l'utilisateur MySQL, puis écrit le fichier.
Les mots de passe sont saisis **sans écho** — ils n'apparaissent ni à l'écran,
ni dans l'historique du shell, ni dans la liste des processus, contrairement à
un `--password=…` que n'importe quel autre compte de la machine pourrait lire.

Les valeurs demandées se trouvent dans hPanel › Avancé › Accès SSH (adresse,
port, identifiant `uXXXXXXXXX`) et hPanel › Bases de données MySQL.

Pour scripter l'installation, les mêmes valeurs passent en options —
`--host=`, `--port=`, `--user=`, `--key=`, `--mysql-user=`, `--local`,
`--no-interactive`. Évite `--password=` sur une machine partagée, pour la
raison ci-dessus.

Au premier `doctor`, épingle l'empreinte du serveur affichée dans
`ssh.host_key_fingerprint` : tes identifiants ne partiront plus ensuite que
vers ce serveur précis.

#### B — directement sur l'hébergement

Souvent le plus simple quand on a déjà SSH : rien à configurer côté
authentification, et le scan est bien plus rapide puisqu'il n'y a plus
d'aller-retour réseau par fichier lu.

```bash
ssh -p <port> <uXXXXXXXXX>@<ip-ssh>
git clone <ce-dépôt> ~/hspace && cd ~/hspace
composer install
php bin/hspace init --local     # puis les questions MySQL
php bin/hspace doctor
php bin/hspace scan
```

Si `composer` n'est pas disponible sur ton plan, envoie simplement le dossier
`vendor/` depuis ta machine : `scp -rP <port> vendor <uXXXXXXXXX>@<ip-ssh>:~/hspace/`.

`scan` affiche déjà l'essentiel dans le terminal, et `orphans`, `sites` et
`findings` relisent le dernier relevé sans rien rescanner. Pour l'interface
web, rapatrie l'inventaire — c'est un simple fichier SQLite :

```bash
scp -P <port> <uXXXXXXXXX>@<ip-ssh>:~/hspace/var/inventory.sqlite ./var/
php bin/hspace serve
```

Dans les deux cas, `config/config.php` est ignoré par git et créé en `0600` :
tes identifiants ne partiront pas sur GitHub.

---

## Héberger l'interface sur un sous-domaine

Pour consulter l'inventaire depuis n'importe où, sans lancer de serveur local.

### Avant tout : cette interface doit être protégée

Elle publie les noms de tes bases, tes utilisateurs MySQL, les chemins sur le
disque et les faiblesses repérées sur chaque site. Publiée telle quelle, c'est
un mode d'emploi pour attaquer ton hébergement.

hspace refuse donc de fonctionner sans mot de passe dès que la requête vient
d'ailleurs que de `127.0.0.1` : au lieu des données, il affiche la marche à
suivre pour le définir. Ce n'est pas un avertissement qu'on peut ignorer — rien
ne s'affiche tant que la protection n'est pas en place.

### Les étapes

**1. Créer le sous-domaine.** hPanel › Domaines › Sous-domaines. Crée par
exemple `inventaire.tondomaine.fr`, et surtout : donne comme **dossier
personnalisé** `hspace/public`, pas `hspace`.

C'est le point à ne pas rater. Si la racine web pointe sur `hspace/`, alors
`config/config.php` — qui contient ton mot de passe SSH et tes accès MySQL —
devient téléchargeable. Un `.htaccess` à la racine du projet bloque ce cas par
sécurité, mais mieux vaut ne pas dépendre de lui.

**2. Vérifier la version de PHP.** hPanel › Avancé › Configuration PHP : il
faut **8.2 ou plus**, avec `pdo_sqlite` activé.

**3. Déposer le projet.**

```bash
ssh -p <port> <uXXXXXXXXX>@<ip-ssh>
git clone -b <branche> <ce-dépôt> ~/hspace && cd ~/hspace
composer install --no-dev
```

**4. Configurer et protéger.**

```bash
php bin/hspace init --local   # mode local : l'app lit le disque en direct
php bin/hspace passwd         # obligatoire — saisie masquée
php bin/hspace doctor
php bin/hspace scan
```

`passwd` ne stocke que le condensé bcrypt du mot de passe. Choisis-le long : il
n'a pas à être mémorisable, un gestionnaire de mots de passe suffit.

**5. Ouvrir `https://inventaire.tondomaine.fr`.** La page de connexion
apparaît. Après identification, l'interface complète.

### Rafraîchir l'inventaire tout seul

hPanel › Avancé › Tâches Cron, une fois par semaine :

```
0 5 * * 1 cd ~/hspace && /usr/bin/php bin/hspace scan >> var/scan.log 2>&1
```

L'interface montrera le nouveau relevé, et `Historique` dira ce qui a changé.

### Ce qui protège quoi

| | |
|---|---|
| Racine web sur `public/` | Le code, la configuration et la base d'inventaire sont hors d'atteinte du web |
| `.htaccess` racine | Filet si la racine web est mal pointée : refuse `config/`, `var/`, `src/`… |
| Mot de passe obligatoire | Rien ne s'affiche depuis l'extérieur sans identification |
| Condensé bcrypt | Même en lisant la configuration, on ne retrouve pas le mot de passe |
| Jeton anti-rejeu | Le formulaire de connexion ne peut pas être soumis depuis un autre site |
| Blocage après 5 essais | Une minute d'attente, en plus de la lenteur propre à bcrypt |
| `X-Robots-Tag: noindex` | L'inventaire ne se retrouve pas dans un moteur de recherche |

Depuis `127.0.0.1`, aucun mot de passe n'est demandé : `hspace serve` sur ton
poste s'adresse déjà à son propriétaire.

---

## Ce qu'il faut préparer dans hPanel

**Accès SSH** — hPanel › Avancé › Accès SSH. Note l'IP, le port (rarement 22
chez Hostinger) et l'utilisateur `uXXXXXXXXX`. Une clé privée est préférable à
un mot de passe.

**Un utilisateur MySQL qui voit toutes tes bases** — c'est le point qui
détermine la fiabilité du résultat, et il mérite deux minutes d'attention.

Sur un hébergement mutualisé, un utilisateur MySQL ne voit que les bases
auxquelles il a été rattaché. Un `SHOW DATABASES` avec le compte d'un site ne
montre donc que la base de ce site — et une base qu'aucun site n'utilise, c'est
justement une base qu'aucun compte de site ne voit.

Crée donc dans hPanel › Bases de données MySQL un utilisateur, rattache-le à
**toutes** tes bases, et renseigne-le dans `mysql.admin_user`.

Sans lui, hspace fonctionne quand même : il agrège les accès trouvés dans les
sites. Mais il te le dira en tête de rapport, présentera les orphelines comme
des pistes plutôt que des faits, et ne déclarera aucun site cassé — parce
qu'une base invisible n'est pas une base absente.

---

## Les commandes

| Commande | Rôle |
|---|---|
| `init` | Crée la configuration, en mode guidé (questions une à une) |
| `doctor` | Vérifie configuration, connexion, arborescence et accès MySQL |
| `scan` | Lance un relevé complet et l'enregistre |
| `sites` | Liste les sites et leurs bases |
| `orphans` | Liste les bases qu'aucun site n'utilise |
| `findings` | Constats du dernier relevé (`--critical`, `--warning`) |
| `history` | Relevés enregistrés |
| `diff <a> <b>` | Compare deux relevés |
| `serve` | Interface web |
| `passwd` | Définit le mot de passe de l'interface web |
| `demo` | Compte fictif et relevés de démonstration |

Options communes : `--config=<chemin>`, `--demo`, `--verbose`.

Options de `init` : `--host=`, `--port=`, `--user=`, `--key=`, `--mysql-user=`,
`--local`, `--no-interactive`.

---

## Ce que l'outil ne fait pas

**Il ne supprime jamais rien.** Ni base, ni fichier, ni site. Une suppression
déclenchée automatiquement sur la foi d'une heuristique serait le pire défaut
possible ici : une base signalée orpheline à tort emporterait un site avec elle.
hspace affiche la commande de sauvegarde et la recherche à lancer avant
d'agir — la suppression reste ton geste, dans hPanel.

**Il ne conserve aucun mot de passe.** Les identifiants lus dans les sites
servent à interroger MySQL pendant le scan, puis sont oubliés. La base
d'inventaire ne contient aucune colonne de mot de passe, et un test le vérifie
en cherchant les mots de passe du compte fictif dans toutes les tables. Le nom
d'utilisateur, lui, est conservé : c'est ce qui permet de retrouver la bonne
ligne dans hPanel.

**Il n'écrit pas sur l'hébergement**, à une exception près : un fichier
d'options MySQL temporaire en `0600`, dans le dossier personnel, supprimé à la
fin du scan. C'est ce qui évite de passer les identifiants en ligne de commande,
où ils seraient lisibles par les autres comptes de la machine mutualisée.

---

## Détails d'implémentation qui comptent

**Les fichiers de configuration PHP sont lus avec le tokenizer du langage**, pas
avec des expressions régulières. Les `wp-config.php` gardent très souvent
l'ancienne base en commentaire :

```php
// define('DB_NAME', 'u998877_ancienne');
define('DB_NAME', 'u998877_boulangerie');
```

Une regex retiendrait la première, rattacherait le site à la mauvaise base, et
ferait passer la vraie pour orpheline. Le tokenizer gère aussi les guillemets
échappés dans les mots de passe.

**L'empreinte du serveur SSH est vérifiée avant l'envoi des identifiants.**
`doctor` affiche l'empreinte présentée ; épingle-la dans
`ssh.host_key_fingerprint` et aucun mot de passe ne partira plus vers un serveur
qui ne serait pas le tien.

**Tout est mesuré en une seule commande.** Taille, nombre de fichiers et date du
fichier le plus récent sont calculés pour tous les sites d'un coup : un `du` par
site, ce serait cinquante allers-retours SSH là où un seul suffit. Les caches et
dossiers de dépendances sont exclus, sinon un `vendor/` régénéré chaque nuit
ferait passer pour vivant un site figé depuis des années.

**SSH est en PHP pur** (phpseclib3), sans dépendre d'un binaire `ssh`. L'outil
tourne donc aussi bien depuis Windows que déposé sur l'hébergement lui-même
(`mode => 'local'`).

**Les technologies reconnues** : WordPress, Laravel, Symfony, Joomla,
PrestaShop, Drupal, Magento, PHP sur mesure, sites statiques, pages de parcage,
dossiers vides, phpMyAdmin et Adminer. Chacune rend sa version et ses
identifiants de base.

---

## Développement

```bash
php tests/run.php          # toute la suite
php tests/run.php analysis # un seul fichier
```

Les tests tournent sur un compte fictif écrit sur disque et une passerelle MySQL
simulée : aucun hébergement ni serveur réel n'est nécessaire.

```
src/
  Transport/   accès à l'hébergement (SSH ou local), derrière une seule interface
  Mysql/       inventaire des bases, agrégation de plusieurs accès partiels
  Detector/    reconnaissance des applications et lecture de leur configuration
  Scanner/     parcours des dossiers, mesures, orchestration du scan
  Analysis/    rapprochement sites ↔ bases, production des constats
  Storage/     base SQLite, historique, comparaison de relevés
  Console/     commandes
  Web/         interface
  Demo/        compte fictif
```

---

## Automatiser

Un scan hebdomadaire, depuis ta machine ou un petit serveur :

```cron
0 6 * * 1 cd /chemin/vers/hspace && php bin/hspace scan >> var/scan.log 2>&1
```

L'historique se compare ensuite avec `php bin/hspace diff`, qui répond à la
question que la liste seule ne traite pas : est-ce que ça s'améliore ?
