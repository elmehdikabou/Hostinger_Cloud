<?php

declare(strict_types=1);

use HostingerSpace\Console\Application;

test('Les options communes sont retirees des arguments de la commande', function (): void {
    // Regression : « --demo » restait dans la liste et devenait le premier
    // argument positionnel. « diff --demo 1 3 » comparait alors le scan 0 et
    // repondait « il faut au moins deux scans » — avec les deux numeros sous
    // les yeux.
    $parsed = Application::parseGlobalOptions(['diff', '--demo', '1', '3']);

    assertSame(['diff', '1', '3'], $parsed['arguments']);
    assertTrue($parsed['demo']);
});

test("L'option de configuration est extraite ou qu'elle soit", function (): void {
    foreach ([
        ['--config=/tmp/a.php', 'scan'],
        ['scan', '--config=/tmp/a.php'],
    ] as $argv) {
        $parsed = Application::parseGlobalOptions($argv);

        assertSame(['scan'], $parsed['arguments']);
        assertSame('/tmp/a.php', $parsed['config']);
    }
});

test('Le mode verbeux est reconnu sous ses deux formes', function (): void {
    assertTrue(Application::parseGlobalOptions(['scan', '--verbose'])['verbose']);
    assertTrue(Application::parseGlobalOptions(['scan', '-v'])['verbose']);
    assertFalse(Application::parseGlobalOptions(['scan'])['verbose']);
});

test('Les options propres aux commandes sont preservees', function (): void {
    // --critical appartient a « findings », --host a « init » : elles doivent
    // traverser sans etre confondues avec des options communes.
    $parsed = Application::parseGlobalOptions(['findings', '--critical', '--demo']);
    assertSame(['findings', '--critical'], $parsed['arguments']);

    $parsed = Application::parseGlobalOptions(['init', '--host=1.2.3.4', '--port=65002', '--local']);
    assertSame(['init', '--host=1.2.3.4', '--port=65002', '--local'], $parsed['arguments']);
    assertNull($parsed['config']);
});

test('Une ligne vide donne une liste vide, sans erreur', function (): void {
    $parsed = Application::parseGlobalOptions([]);

    assertCount(0, $parsed['arguments']);
    assertFalse($parsed['demo']);
    assertNull($parsed['config']);
});
