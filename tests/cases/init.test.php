<?php

declare(strict_types=1);

use HostingerSpace\Console\Command\InitCommand;
use HostingerSpace\Console\Context;
use HostingerSpace\Console\Output;

$directory = sys_get_temp_dir() . '/hspace-init-' . bin2hex(random_bytes(6));
mkdir($directory, 0o775, true);

/**
 * Lance « init » vers un fichier neuf et rend la configuration produite.
 *
 * @param array<int,string> $arguments
 *
 * @return array<string,mixed>
 */
$init = static function (array $arguments) use ($directory): array {
    $target = $directory . '/config-' . bin2hex(random_bytes(4)) . '.php';
    $silent = fopen('php://memory', 'w+');

    (new InitCommand())->run($arguments, new Output($silent), new Context($target));

    fclose($silent);

    return ['path' => $target, 'values' => require $target];
};

test('Les options remplissent la section SSH', function () use ($init): void {
    $result = $init(['--host=147.79.99.68', '--port=65002', '--user=u736304795']);

    assertSame('147.79.99.68', $result['values']['ssh']['host']);
    assertSame(65002, $result['values']['ssh']['port']);
    assertSame('u736304795', $result['values']['ssh']['username']);
});

test('Un remplacement ne deborde pas sur les autres sections', function () use ($init): void {
    // « host » existe dans ssh, mysql et web. Un remplacement global
    // ecraserait « localhost » et « 127.0.0.1 », et le scan interrogerait
    // alors MySQL sur l'adresse publique du serveur.
    $result = $init(['--host=147.79.99.68']);

    assertSame('147.79.99.68', $result['values']['ssh']['host']);
    assertSame('localhost', $result['values']['mysql']['host']);
    assertSame('127.0.0.1', $result['values']['web']['host']);
});

test('Le chemin de cle privee est pose sans toucher au mot de passe', function () use ($init): void {
    $result = $init(['--key=/home/moi/.ssh/id_ed25519']);

    assertSame('/home/moi/.ssh/id_ed25519', $result['values']['ssh']['private_key_path']);
    assertNull($result['values']['ssh']['password']);
});

test("L'utilisateur MySQL global se renseigne aussi en option", function () use ($init): void {
    $result = $init(['--mysql-user=u736304795_inventaire']);

    assertSame('u736304795_inventaire', $result['values']['mysql']['admin_user']);
});

test('--local bascule le mode sans exiger de SSH', function () use ($init): void {
    $result = $init(['--local']);

    assertSame('local', $result['values']['mode']);
});

test('Sans option, le modele est copie tel quel', function () use ($init): void {
    $result = $init([]);

    assertSame('ssh', $result['values']['mode']);
    assertSame(65002, $result['values']['ssh']['port']);
    assertNull($result['values']['mysql']['admin_user']);
});

test('La configuration est creee en 0600', function () use ($init): void {
    // Elle va contenir des identifiants : elle ne doit jamais etre lisible
    // par les autres comptes de la machine.
    $result = $init(['--host=exemple.fr']);

    assertSame('0600', substr(sprintf('%o', fileperms($result['path'])), -4));
});

test('Une configuration existante n est jamais ecrasee', function () use ($directory): void {
    $target = $directory . '/existante.php';
    file_put_contents($target, "<?php return ['mode' => 'local', 'marqueur' => 'ne pas perdre'];");

    $silent = fopen('php://memory', 'w+');
    $code = (new InitCommand())->run(['--host=autre.fr'], new Output($silent), new Context($target));
    fclose($silent);

    $values = require $target;

    assertSame(0, $code, 'le cas doit etre traite sans erreur');
    assertSame('ne pas perdre', $values['marqueur']);
});

test("Le modele reste trouvable quand la configuration est rangee ailleurs", function () use ($init): void {
    // Le fichier cible est dans un dossier temporaire, loin de config/ :
    // sans repli sur le modele du projet, init echouerait.
    $result = $init(['--host=exemple.fr']);

    assertTrue(isset($result['values']['paths']['domains_dir']));
});

register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});
