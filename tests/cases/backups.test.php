<?php

declare(strict_types=1);

use HostingerSpace\Backup\BackupArtifact;
use HostingerSpace\Backup\BackupAudit;
use HostingerSpace\Backup\BackupScanner;
use HostingerSpace\Config;
use HostingerSpace\Model\FindingKind;
use HostingerSpace\Model\Severity;
use HostingerSpace\Scanner\SiteScanner;
use HostingerSpace\Transport\LocalTransport;

/** Un hebergement avec les sauvegardes qu'on y trouve vraiment. */
function hebergementAvecSauvegardes(): string
{
    $racine = sys_get_temp_dir() . '/hspace-bkp-' . bin2hex(random_bytes(6));
    $site = "{$racine}/domains/boutique.fr/public_html";

    mkdir("{$site}/wp-includes", 0o775, true);
    file_put_contents("{$site}/wp-config.php", "<?php\ndefine('DB_NAME', 'u1_boutique');\ndefine('DB_USER', 'u1_boutique');\n");
    file_put_contents("{$site}/wp-includes/version.php", "<?php\n\$wp_version='6.5';");

    // Le pire cas, et le plus courant : un dump laisse a la racine web.
    file_put_contents("{$site}/backup.sql", str_repeat("INSERT INTO clients VALUES(1);\n", 200));

    // Une archive de greffon, exposee elle aussi.
    mkdir("{$site}/wp-content/updraft", 0o775, true);
    file_put_contents("{$site}/wp-content/updraft/db.gz", str_repeat('x', 5000));

    // Une sauvegarde rangee hors des racines web : celle-la va bien.
    mkdir("{$racine}/sauvegardes", 0o775, true);
    file_put_contents("{$racine}/sauvegardes/boutique-2026.sql.gz", str_repeat('y', 9000));

    // Un dump interrompu : le fichier existe, mais ne contient rien.
    file_put_contents("{$racine}/sauvegardes/vide.sql", '');

    // Un second site, sans la moindre sauvegarde.
    mkdir("{$racine}/domains/vitrine.fr/public_html", 0o775, true);
    file_put_contents("{$racine}/domains/vitrine.fr/public_html/index.html", '<h1>vitrine</h1>');

    return $racine;
}

function effacerArborescence(string $chemin): void
{
    foreach (glob($chemin . '/*') ?: [] as $entree) {
        is_dir($entree) ? effacerArborescence($entree) : unlink($entree);
    }

    @rmdir($chemin);
}

test('Une sauvegarde sous une racine web est signalee comme telechargeable', function (): void {
    /*
     * Le constat le plus grave que l'outil puisse faire, et le moins visible
     * sans lui. Un dump SQL sous public_html se telecharge en devinant son
     * nom : la base entiere part — comptes, adresses, mots de passe haches —
     * sans faille a exploiter et sans trace dans aucun journal.
     *
     * Il passe donc devant « aucune sauvegarde » : sans sauvegarde on risque
     * de perdre ses donnees, avec une sauvegarde exposee on les a deja livrees.
     */
    $racine = hebergementAvecSauvegardes();

    $config = Config::fromArray([
        'mode' => 'local',
        'paths' => ['home' => $racine, 'domains_dir' => $racine . '/domains', 'ignore' => []],
        'analysis' => ['max_depth' => 3],
    ]);

    $transport = new LocalTransport($racine);
    $sites = (new SiteScanner($transport, $config))->scan();
    $sauvegardes = (new BackupScanner($transport))->scan($sites);

    $parChemin = [];

    foreach ($sauvegardes as $sauvegarde) {
        $parChemin[$sauvegarde->path] = $sauvegarde;
    }

    $expose = $parChemin['domains/boutique.fr/public_html/backup.sql'] ?? null;

    assertTrue($expose !== null, 'le dump a la racine web doit etre trouve');
    assertTrue($expose->webReachable, 'il est sous public_html, donc telechargeable');
    assertSame('dump', $expose->kind);

    // L'archive du greffon compte aussi : elle contient le dump.
    $greffon = $parChemin['domains/boutique.fr/public_html/wp-content/updraft/db.gz'] ?? null;
    assertTrue($greffon !== null && $greffon->webReachable);

    // Celle rangee hors des racines web ne doit pas etre signalee.
    $rangee = $parChemin['sauvegardes/boutique-2026.sql.gz'] ?? null;
    assertTrue($rangee !== null, 'une sauvegarde hors racine web doit rester inventoriee');
    assertFalse($rangee->webReachable, 'elle n\'est servie par aucun site');

    $constats = (new BackupAudit())->findings($sauvegardes, $sites);
    $exposes = array_values(array_filter(
        $constats,
        static fn ($c): bool => $c->kind === FindingKind::ExposedBackup
    ));

    assertTrue(count($exposes) >= 2, 'les deux fichiers exposes doivent ressortir');
    assertSame(Severity::Critical, $exposes[0]->severity);
    assertContains('mv ', implode(' ', $exposes[0]->actions), 'le constat doit dire quoi faire');

    effacerArborescence($racine);
});

test('Un site sans la moindre sauvegarde est signale, sans exces de certitude', function (): void {
    $racine = hebergementAvecSauvegardes();

    $config = Config::fromArray([
        'mode' => 'local',
        'paths' => ['home' => $racine, 'domains_dir' => $racine . '/domains', 'ignore' => []],
        'analysis' => ['max_depth' => 3],
    ]);

    $transport = new LocalTransport($racine);
    $sites = (new SiteScanner($transport, $config))->scan();
    $constats = (new BackupAudit())->findings((new BackupScanner($transport))->scan($sites), $sites);

    $sans = array_values(array_filter($constats, static fn ($c): bool => $c->kind === FindingKind::NoBackup));
    $sujets = array_map(static fn ($c): string => $c->subject, $sans);

    assertTrue(in_array('domains/vitrine.fr/public_html', $sujets, true), 'le site sans sauvegarde doit ressortir');
    assertFalse(in_array('domains/boutique.fr/public_html', $sujets, true), 'celui qui en a une ne doit pas');

    // L'outil ne voit pas les sauvegardes automatiques de Hostinger : le dire
    // evite de faire croire qu'un site est sans filet alors qu'il ne l'est pas.
    assertContains('hPanel', $sans[0]->detail);

    effacerArborescence($racine);
});

test('Une archive vide ne compte pas pour une sauvegarde', function (): void {
    /*
     * Un dump interrompu laisse un fichier de zero octet portant le bon nom.
     * Le compter comme une protection est pire que de n'en avoir aucune :
     * on cesse de s'en inquieter, et on ne le decouvre qu'en restaurant.
     */
    $vide = new BackupArtifact('sauvegardes/vide.sql', sizeBytes: 0, modifiedAt: time(), kind: 'dump');
    $pleine = new BackupArtifact('sauvegardes/pleine.sql', sizeBytes: 9000, modifiedAt: time(), kind: 'dump');

    assertTrue($vide->looksTruncated());
    assertFalse($pleine->looksTruncated());

    $constats = (new BackupAudit())->findings([$vide], []);
    $tronquees = array_values(array_filter(
        $constats,
        static fn ($c): bool => $c->kind === FindingKind::TruncatedBackup
    ));

    assertCount(1, $tronquees);
    assertSame(Severity::Warning, $tronquees[0]->severity);

    // Un dossier de sauvegarde n'a pas de taille propre : il ne doit pas
    // ressortir comme tronque du seul fait qu'il pese zero.
    assertFalse((new BackupArtifact('site/updraft', 0, time(), 'dossier'))->looksTruncated());
});

test('Une sauvegarde ancienne est datee, pas passee sous silence', function (): void {
    $vieille = new BackupArtifact('sauvegardes/2025.sql', 50_000, time() - 200 * 86_400, 'dump');
    $fraiche = new BackupArtifact('sauvegardes/hier.sql', 50_000, time() - 86_400, 'dump');

    assertSame(200, $vieille->ageInDays());
    assertTrue($vieille->isStale(30));
    assertFalse($fraiche->isStale(30));

    $constats = (new BackupAudit(30))->findings([$vieille, $fraiche], []);
    $anciennes = array_values(array_filter(
        $constats,
        static fn ($c): bool => $c->kind === FindingKind::StaleBackup
    ));

    assertCount(1, $anciennes);
    assertContains('200 jours', $anciennes[0]->detail);
});

test('Un dossier expose ne fait qu un constat, et un conseil qui ne casse rien', function (): void {
    /*
     * Un dossier de greffon contient dix archives, exposees pour la meme
     * raison et corrigees par le meme geste. Une ligne par archive noierait
     * les autres constats et ferait croire a dix problemes.
     *
     * Et le conseil doit tenir compte de ce qu'est ce dossier : le deplacer
     * casserait le greffon, qui y ecrit a chaque execution.
     */
    $dossier = new BackupArtifact('site/wp-content/updraft', 0, time(), 'dossier', 'site', webReachable: true);
    $dedans = [
        new BackupArtifact('site/wp-content/updraft/db-1.gz', 5000, time(), 'archive', 'site', webReachable: true),
        new BackupArtifact('site/wp-content/updraft/db-2.gz', 5000, time(), 'archive', 'site', webReachable: true),
    ];
    $dehors = new BackupArtifact('site/dump.sql', 9000, time(), 'dump', 'site', webReachable: true);

    $constats = (new BackupAudit())->findings([$dossier, ...$dedans, $dehors], []);
    $exposes = array_values(array_filter(
        $constats,
        static fn ($c): bool => $c->kind === FindingKind::ExposedBackup
    ));

    assertCount(2, $exposes, 'le dossier et le fichier isole, pas les archives du dossier');

    $sujets = array_map(static fn ($c): string => $c->subject, $exposes);
    assertTrue(in_array('site/wp-content/updraft', $sujets, true));
    assertTrue(in_array('site/dump.sql', $sujets, true));
    assertFalse(in_array('site/wp-content/updraft/db-1.gz', $sujets, true));

    // Le conseil protege sur place au lieu de deplacer, sans quoi le greffon
    // cesserait de fonctionner au premier passage.
    $actions = implode(' ', $exposes[0]->actions);
    assertContains('.htaccess', $actions);
    assertFalse(str_contains($actions, 'mv '), 'deplacer le dossier casserait le greffon');
});
