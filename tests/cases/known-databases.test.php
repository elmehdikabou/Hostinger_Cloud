<?php

declare(strict_types=1);

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Mysql\DatabaseInfo;
use HostingerSpace\Mysql\DatabaseInventory;
use HostingerSpace\Mysql\KnownDatabases;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Transport\LocalTransport;

test('Le collage vertical de hPanel ne confond pas base et utilisateur', function (): void {
    /*
     * Le format que produit reellement hPanel : base et utilisateur sur deux
     * lignes distinctes, separes par « Acceder a phpMyAdmin ». Prendre les
     * deux ferait apparaitre l'utilisateur comme une base qu'aucun site ne
     * declare — donc une orpheline inventee, celle qui fait supprimer la
     * mauvaise chose.
     */
    $colle = <<<'TXT'
        Base de données MySQL
        Utilisateur MySQL
        Action
        u1_boutique

        u1_boutique

        Accéder à phpMyAdmin
        u1_t22AF

        u1_Mq6ZV

        Accéder à phpMyAdmin
        u1_blog

        u1_blog
        TXT;

    $noms = KnownDatabases::parse($colle);

    assertSame(['u1_blog', 'u1_boutique', 'u1_t22AF'], $noms);
    assertFalse(in_array('u1_Mq6ZV', $noms, true), "« u1_Mq6ZV » est un utilisateur, pas une base");
});

test('Le collage en deux colonnes ne retient que la premiere', function (): void {
    $noms = KnownDatabases::parse("u1_alpha\tu1_alpha\nu1_beta\tu1_xY9Zk\n");

    assertSame(['u1_alpha', 'u1_beta'], $noms);
    assertFalse(in_array('u1_xY9Zk', $noms, true));
});

test('Une liste deja rangee est relue en entier', function (): void {
    // Regression : la regle « une base par bloc », juste pour le collage
    // hPanel, ne relisait qu'un seul nom d'un fichier a une base par ligne.
    // L'inventaire se vidait alors en silence d'un import a l'autre.
    $fichier = "# Liste des bases\n# 3 bases\n\nu1_alpha\nu1_beta\nu1_gamma\n";

    assertSame(['u1_alpha', 'u1_beta', 'u1_gamma'], KnownDatabases::parse($fichier));
});

test('Les en-tetes et schemas systeme sont ecartes', function (): void {
    $noms = KnownDatabases::parse("Base\nUtilisateur\nAction\ninformation_schema\nmysql\nu1_reel\n");

    assertSame(['u1_reel'], $noms);
});

test('Un texte vide ne renvoie rien', function (): void {
    assertCount(0, KnownDatabases::parse(''));
    assertCount(0, KnownDatabases::parse("\n   \n"));
    assertCount(0, KnownDatabases::fromFile('/chemin/absent.txt'));
});

test('Le bruit du collage est ecarte par le prefixe de compte', function (): void {
    // Un mot attrape au passage — un titre, une legende — deviendrait sinon
    // une base qu'aucun site ne declare, donc une orpheline inventee. Toutes
    // les bases d'un compte mutualise partagent leur prefixe : on s'en sert.
    $noms = KnownDatabases::parse("Bienvenue\nu1_alpha\nu1_beta\nu1_gamma\nSupprimer\n");

    assertSame(['u1_alpha', 'u1_beta', 'u1_gamma'], $noms);
});

test('Sans prefixe commun, rien n est ecarte', function (): void {
    // Sur un serveur ou les bases n'ont pas de prefixe de compte, filtrer
    // dessus supprimerait de vraies bases.
    $noms = KnownDatabases::parse("boutique\nblog\nfacturation\nsupport\n");

    assertSame(['blog', 'boutique', 'facturation', 'support'], $noms);
});

test('La liste declaree rend les orphelines decidables sans MySQL', function (): void {
    /*
     * Le cas reel d'un mutualise Hostinger : chaque base a son propre
     * utilisateur, aucun compte ne les voit toutes, et l'inventaire par MySQL
     * est donc vide. La liste collee depuis hPanel suffit pourtant a repondre,
     * puisque decider qu'une base ne sert a personne ne demande pas de
     * l'ouvrir.
     */
    $racine = sys_get_temp_dir() . '/hspace-declare-' . bin2hex(random_bytes(6));

    foreach (['boutique', 'blog'] as $nom) {
        mkdir("{$racine}/domains/{$nom}.fr/public_html/wp-includes", 0o775, true);
        file_put_contents("{$racine}/domains/{$nom}.fr/public_html/wp-config.php", <<<PHP
            <?php
            define('DB_NAME', 'u1_{$nom}');
            define('DB_USER', 'u1_{$nom}');
            define('DB_PASSWORD', 'x');
            define('DB_HOST', 'localhost');
            PHP);
        file_put_contents("{$racine}/domains/{$nom}.fr/public_html/wp-includes/version.php", "<?php\n\$wp_version='6.5';");
    }

    $config = Config::fromArray([
        'mode' => 'local',
        'paths' => ['home' => $racine, 'domains_dir' => $racine . '/domains', 'ignore' => []],
        'analysis' => ['max_depth' => 3],
    ]);

    $sites = (new SiteScanner(new LocalTransport($racine), $config))->scan();

    // Cinq bases existent, deux seulement sont utilisees.
    $declarees = ['u1_blog', 'u1_boutique', 'u1_t22AF', 'u1_vieux', 'u1_test'];
    $databases = [];

    foreach ($declarees as $nom) {
        $databases[$nom] = new DatabaseInfo($nom);
    }

    ksort($databases, SORT_NATURAL | SORT_FLAG_CASE);

    $analyse = (new Linker(180))->analyse(
        $sites,
        new DatabaseInventory($databases, [], complete: true, declared: true)
    );

    assertSame(['u1_t22AF', 'u1_test', 'u1_vieux'], $analyse->orphans);

    // Une base connue seulement par son nom n'est pas « vide » : son contenu
    // est inconnu. La declarer vide inviterait a la supprimer sans regarder.
    assertFalse($databases['u1_vieux']->isEmpty());
    assertTrue($databases['u1_vieux']->unmeasured());

    // Nettoyage.
    $supprimer = static function (string $chemin) use (&$supprimer): void {
        foreach (glob($chemin . '/*') ?: [] as $entree) {
            is_dir($entree) ? $supprimer($entree) : unlink($entree);
        }

        @rmdir($chemin);
    };

    $supprimer($racine);
});

test('Une configuration anterieure a la cle retrouve quand meme la liste', function (): void {
    /*
     * Regression : « mysql.known_databases_file » est arrivee apres la mise
     * en service. Une configuration ecrite avant ne la porte pas — et l'import
     * ecrivait alors databases.txt a cote du config.php pendant que le releve
     * cherchait un chemin nul. La liste importee etait ignoree en silence :
     * l'utilisateur voyait « 45 bases enregistrees », puis zero au scan.
     */
    $racine = sys_get_temp_dir() . '/hspace-cle-' . bin2hex(random_bytes(6));
    mkdir($racine, 0o775, true);
    file_put_contents($racine . '/databases.txt', "u1_alpha\nu1_beta\nu1_gamma\n");

    $ancienne = Config::fromArray(
        ['mode' => 'local', 'mysql' => ['user' => 'x']],
        $racine . '/config.php',
    );

    assertSame($racine . '/databases.txt', $ancienne->knownDatabasesFile());
    assertCount(3, KnownDatabases::fromFile($ancienne->knownDatabasesFile()));

    // Une cle explicite reste prioritaire sur le voisinage.
    $explicite = Config::fromArray(
        ['mysql' => ['known_databases_file' => '/ailleurs/liste.txt']],
        $racine . '/config.php',
    );

    assertSame('/ailleurs/liste.txt', $explicite->knownDatabasesFile());

    // Une configuration montee en memoire n'a pas de voisin sur le disque :
    // sans ce garde-fou elle lirait un databases.txt du dossier courant.
    assertSame(null, Config::fromArray([])->knownDatabasesFile());

    unlink($racine . '/databases.txt');
    rmdir($racine);
});

test('La liste declaree complete les mesures au lieu de les ecraser', function (): void {
    /*
     * Le cas reel : 13 bases sont ouvertes par les acces lus dans les sites et
     * pesent 2,7 Go a elles seules ; hPanel en declare 45. Si la fusion
     * repartait de noms nus, ces 2,7 Go — les seules mesures disponibles —
     * disparaitraient, et l'espace occupe redeviendrait inconnu alors qu'il
     * etait connu.
     */
    $mesuree = new DatabaseInfo('u1_boutique', sizeBytes: 2_700_000_000, tableCount: 48);
    $mesuree->measured = true;
    $mesuree->addSource('wp-config.php');

    $inventaire = new DatabaseInventory(['u1_boutique' => $mesuree], probes: [], complete: false);

    $fusionne = $inventaire->withDeclared(['u1_boutique', 'u1_t22AF', 'u1_Ceab4']);

    assertCount(3, $fusionne->databases);
    assertSame(2_700_000_000, $fusionne->get('u1_boutique')?->sizeBytes);
    assertSame(48, $fusionne->get('u1_boutique')?->tableCount);
    assertFalse($fusionne->get('u1_boutique')?->unmeasured());

    // La base mesuree porte desormais ses deux origines, la declaration
    // n'effaçant pas la trace de l'acces qui l'avait ouverte.
    assertSame(['wp-config.php', 'liste hPanel'], $fusionne->get('u1_boutique')?->discoveredVia);

    // Les nouvelles arrivent sans mesure, et le disent.
    assertTrue($fusionne->get('u1_t22AF')?->unmeasured());
    assertSame(2, $fusionne->unmeasuredCount());

    // Le total reste celui des bases reellement pesees.
    assertSame(2_700_000_000, $fusionne->totalSize());
    assertTrue($fusionne->complete);
});

test('Une liste declaree suffit meme sans le moindre acces MySQL', function (): void {
    $inventaire = new DatabaseInventory(
        ['u1_alpha' => new DatabaseInfo('u1_alpha')],
        probes: [],
        complete: true,
        declared: true,
    );

    // Sans la liste, ce message dirait « aucune conclusion n'est possible ».
    assertContains('Liste complete', $inventaire->coverageNote());
    assertContains('taille', $inventaire->coverageNote());
    assertSame(1, $inventaire->unmeasuredCount());
});

test('Un scan a couverture partielle reclame la liste au lieu de se taire', function (): void {
    /*
     * Trois relances de « scan » d'affilee peuvent rendre 13 bases et zero
     * orpheline sans qu'aucune ligne n'indique qu'il manque l'etape decisive.
     * Le resultat a l'air normal, et l'etape la plus importante de l'outil
     * reste invisible a qui ne l'a pas lue ailleurs. Le moment ou le manque
     * est constate est le seul ou l'instruction sert : elle s'affiche donc la.
     */
    $racine = sys_get_temp_dir() . '/hspace-reclame-' . bin2hex(random_bytes(6));
    mkdir($racine . '/domains/site.fr/public_html', 0o775, true);
    file_put_contents($racine . '/domains/site.fr/public_html/index.html', '<h1>site</h1>');

    $configPath = $racine . '/config.php';
    file_put_contents($configPath, '<?php return ' . var_export([
        'mode' => 'local',
        'paths' => ['home' => $racine, 'domains_dir' => $racine . '/domains', 'ignore' => []],
        'mysql' => ['credentials' => []],
        'storage' => ['database' => $racine . '/inventaire.sqlite'],
        'network' => ['check_http' => false, 'check_ssl' => false, 'check_domain' => false],
        'analysis' => ['max_depth' => 3],
    ], true) . ';');

    $flux = fopen('php://memory', 'w+');
    (new HostingerSpace\Console\Command\ScanCommand())->run(
        [],
        new HostingerSpace\Console\Output($flux),
        new HostingerSpace\Console\Context($configPath),
    );

    rewind($flux);
    $affiche = (string) stream_get_contents($flux);
    fclose($flux);

    assertContains('import-databases', $affiche, "le scan doit nommer la commande qui debloque la situation");
    assertContains('indeterminable', $affiche, "zero orpheline ne doit pas etre presente comme un resultat");

    // Et une fois la liste en place, le rappel disparait : il ne doit pas
    // devenir un avertissement permanent qu'on apprend a ignorer.
    file_put_contents($racine . '/databases.txt', "u1_alpha\nu1_beta\nu1_gamma\n");

    $flux = fopen('php://memory', 'w+');
    (new HostingerSpace\Console\Command\ScanCommand())->run(
        [],
        new HostingerSpace\Console\Output($flux),
        new HostingerSpace\Console\Context($configPath),
    );

    rewind($flux);
    $apres = (string) stream_get_contents($flux);
    fclose($flux);

    assertFalse(str_contains($apres, 'Il manque la liste'), 'le rappel doit disparaitre une fois la liste lue');
    assertContains('3', $apres);

    $supprimer = static function (string $chemin) use (&$supprimer): void {
        foreach (glob($chemin . '/*') ?: [] as $entree) {
            is_dir($entree) ? $supprimer($entree) : unlink($entree);
        }

        @rmdir($chemin);
    };

    $supprimer($racine);
});

test('Une liste que le serveur contredit est signalee comme incomplete', function (): void {
    /*
     * Le cas reel : 45 noms colles depuis hPanel, mais MySQL en montre 12
     * autres qui n'y figurent pas. hPanel pagine, et le collage s'est arrete
     * au premier ecran. L'outil ne peut pas deviner les noms manquants — il
     * peut en revanche constater qu'il en manque, et cesser d'annoncer une
     * liste complete. Sans quoi « 44 orphelines » se lit comme un total alors
     * que c'est un minimum.
     */
    $vues = [];

    foreach (range(1, 12) as $i) {
        $info = new DatabaseInfo("u1_horsliste{$i}");
        $info->measured = true;
        $info->sizeBytes = 207_000_000;
        $vues["u1_horsliste{$i}"] = $info;
    }

    $vues['u1_commune'] = new DatabaseInfo('u1_commune');
    $vues['u1_commune']->measured = true;

    $declarees = array_map(static fn (int $i): string => "u1_declaree{$i}", range(1, 44));
    $declarees[] = 'u1_commune';

    $fusionne = (new DatabaseInventory($vues, probes: [], complete: false))->withDeclared($declarees);

    // 13 vues + 45 declarees, une seule en commun : 57 au total, comme sur le
    // serveur reel.
    assertCount(57, $fusionne->databases);
    assertSame(12, $fusionne->declaredGaps);
    assertContains('Liste incomplete', $fusionne->coverageNote());
    assertContains('12 base(s) que ta liste ne mentionne pas', $fusionne->coverageNote());

    // Les mesures des 13 bases vues survivent a la fusion.
    assertSame(12 * 207_000_000, $fusionne->totalSize());

    // Une liste qui couvre tout ce que MySQL voit ne declenche rien.
    $complete = (new DatabaseInventory($vues, probes: [], complete: false))
        ->withDeclared([...$declarees, ...array_keys($vues)]);

    assertSame(0, $complete->declaredGaps);
    assertContains('Liste complete', $complete->coverageNote());
});
