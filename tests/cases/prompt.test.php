<?php

declare(strict_types=1);

use HostingerSpace\Console\Output;
use HostingerSpace\Console\Prompt;

/**
 * Construit un dialogue joue d'avance : les reponses sont fournies, et la
 * sortie est capturee pour verifier ce qui a ete affiche.
 *
 * @param array<int,string> $answers
 *
 * @return array{prompt:Prompt,shown:callable():string}
 */
$dialogue = static function (array $answers): array {
    $in = fopen('php://memory', 'r+');
    fwrite($in, implode("\n", $answers) . "\n");
    rewind($in);

    $out = fopen('php://memory', 'r+');

    return [
        'prompt' => new Prompt(new Output($out), $in, interactive: true),
        'shown' => static function () use ($out): string {
            rewind($out);

            return (string) stream_get_contents($out);
        },
    ];
};

test('Une reponse vide retient la valeur proposee', function () use ($dialogue): void {
    ['prompt' => $prompt] = $dialogue(['', '65002']);

    assertSame('65002', $prompt->ask('Port SSH :', '65002'));
    assertSame('65002', $prompt->ask('Port SSH :'));
});

test('Une valeur obligatoire est redemandee tant qu elle manque', function () use ($dialogue): void {
    ['prompt' => $prompt, 'shown' => $shown] = $dialogue(['', '', '147.79.99.68']);

    assertSame('147.79.99.68', $prompt->ask('Adresse IP :', required: true));
    assertContains('obligatoire', $shown());
});

test('Une reponse facultative laissee vide vaut null', function () use ($dialogue): void {
    ['prompt' => $prompt] = $dialogue(['']);

    assertNull($prompt->ask('Utilisateur MySQL :'));
});

test('Un choix hors liste est refuse puis redemande', function () use ($dialogue): void {
    ['prompt' => $prompt, 'shown' => $shown] = $dialogue(['nimportequoi', 'local']);

    $choice = $prompt->choose('Ou tourner ?', ['ssh' => 'a distance', 'local' => 'sur place'], 'ssh');

    assertSame('local', $choice);
    assertContains('Reponse attendue', $shown());
});

test('Entree seule retient le choix par defaut', function () use ($dialogue): void {
    ['prompt' => $prompt] = $dialogue(['']);

    assertSame('ssh', $prompt->choose('Ou tourner ?', ['ssh' => 'a distance', 'local' => 'sur place'], 'ssh'));
});

test('La confirmation accepte les formes francaises et anglaises', function () use ($dialogue): void {
    ['prompt' => $prompt] = $dialogue(['o', 'oui', 'y', 'n', 'non', '']);

    assertTrue($prompt->confirm('Ecrire ?'));
    assertTrue($prompt->confirm('Ecrire ?'));
    assertTrue($prompt->confirm('Ecrire ?'));
    assertFalse($prompt->confirm('Ecrire ?'));
    assertFalse($prompt->confirm('Ecrire ?'));
    assertTrue($prompt->confirm('Ecrire ?'), 'Entree seule retient la valeur par defaut');
});

test("Un secret n'est jamais reaffiche", function () use ($dialogue): void {
    ['prompt' => $prompt, 'shown' => $shown] = $dialogue(['MonMotDePasse']);

    assertSame('MonMotDePasse', $prompt->askSecret('Mot de passe :'));
    assertFalse(
        str_contains($shown(), 'MonMotDePasse'),
        "le mot de passe ne doit pas ressortir dans la sortie du terminal"
    );
});

test('Un secret laisse vide vaut null', function () use ($dialogue): void {
    ['prompt' => $prompt] = $dialogue(['']);

    assertNull($prompt->askSecret('Phrase de passe :'));
});

test("Sans terminal, le mode guide est desactive", function (): void {
    // Garde-fou : une tache planifiee ne doit jamais se bloquer sur une
    // question que personne ne lira.
    $in = fopen('php://memory', 'r+');
    $out = fopen('php://memory', 'r+');

    assertFalse((new Prompt(new Output($out), $in))->isInteractive());
});
