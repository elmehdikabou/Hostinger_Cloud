<?php

declare(strict_types=1);

use HostingerSpace\Mysql\BatchOutput;
use HostingerSpace\Mysql\DatabaseInspector;
use HostingerSpace\Mysql\MysqlCredential;
use HostingerSpace\Mysql\MysqlException;
use HostingerSpace\Mysql\MysqlGateway;

/**
 * Passerelle factice : rejoue des reponses MySQL preenregistrees, pour tester
 * l'agregation sans serveur.
 */
final class FakeGateway implements MysqlGateway
{
    public bool $closed = false;

    /** @param array<string,list<array<string,?string>>|MysqlException> $responses  Motif de requete => reponse. */
    public function __construct(
        private readonly string $label,
        private readonly array $responses,
    ) {
    }

    public function query(string $sql): array
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($sql, $needle)) {
                if ($response instanceof MysqlException) {
                    throw $response;
                }

                return $response;
            }
        }

        return [];
    }

    public function probe(): ?string
    {
        return null;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

test('BatchOutput lit un tableau TSV avec en-tete', function (): void {
    $rows = BatchOutput::parse("name\tsize\nu1_blog\t4096\nu1_shop\t8192\n");

    assertCount(2, $rows);
    assertSame('u1_blog', $rows[0]['name']);
    assertSame('8192', $rows[1]['size']);
});

test('BatchOutput traduit NULL en null PHP', function (): void {
    $rows = BatchOutput::parse("name\tupdated_at\nu1_blog\tNULL\n");

    assertNull($rows[0]['updated_at']);
});

test('BatchOutput defait les echappements de mysql', function (): void {
    // mysql echappe tabulations et sauts de ligne : sans decodage, une valeur
    // contenant une tabulation decalerait toutes les colonnes suivantes.
    $rows = BatchOutput::parse("name\tnote\nu1_blog\tavant\\tapres\\nsuite\n");

    assertCount(1, $rows);
    assertSame("avant\tapres\nsuite", $rows[0]['note']);
    assertSame('u1_blog', $rows[0]['name']);
});

test('BatchOutput sur une sortie vide ne renvoie rien', function (): void {
    assertCount(0, BatchOutput::parse(''));
    assertCount(0, BatchOutput::parse("\n"));
});

test("L'inspecteur ignore les schemas systeme", function (): void {
    $inspector = new DatabaseInspector(fn () => new FakeGateway('test@localhost', [
        'information_schema.SCHEMATA' => [
            ['name' => 'information_schema', 'charset' => 'utf8', 'collation' => null],
            ['name' => 'mysql', 'charset' => 'utf8', 'collation' => null],
            ['name' => 'performance_schema', 'charset' => 'utf8', 'collation' => null],
            ['name' => 'u1_blog', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ],
    ]));

    $inventory = $inspector->inspect([new MysqlCredential('u1', 'x')]);

    assertSame(['u1_blog'], $inventory->names());
    assertSame('utf8mb4', $inventory->get('u1_blog')?->charset);
});

test("L'inspecteur fait l'union de plusieurs acces partiels", function (): void {
    // Le cas reel du mutualise : chaque compte MySQL ne voit que ses bases.
    $gateways = [
        'a' => new FakeGateway('u1_a@localhost', [
            'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => 'utf8mb4', 'collation' => null]],
            'information_schema.TABLES' => [[
                'name' => 'u1_blog', 'table_count' => '12', 'size_bytes' => '4096',
                'row_estimate' => '300', 'updated_at' => '2026-01-05 10:00:00', 'created_at' => '2024-01-01 09:00:00',
            ]],
        ]),
        'b' => new FakeGateway('u1_b@localhost', [
            'information_schema.SCHEMATA' => [['name' => 'u1_shop', 'charset' => 'utf8mb4', 'collation' => null]],
        ]),
    ];

    $queue = array_values($gateways);
    $inspector = new DatabaseInspector(function () use (&$queue): MysqlGateway {
        return array_shift($queue);
    });

    $inventory = $inspector->inspect([
        new MysqlCredential('u1_a', 'x'),
        new MysqlCredential('u1_b', 'y'),
    ]);

    assertSame(['u1_blog', 'u1_shop'], $inventory->names());
    assertSame(12, $inventory->get('u1_blog')?->tableCount);
    assertTrue($inventory->get('u1_shop')?->isEmpty());
    assertFalse($inventory->complete);
    assertContains('Couverture partielle', $inventory->coverageNote());
});

test("Un acces ON *.* rend la couverture complete", function (): void {
    $inspector = new DatabaseInspector(fn () => new FakeGateway('admin@localhost', [
        'SHOW GRANTS' => [['Grants' => "GRANT ALL PRIVILEGES ON *.* TO `admin`@`localhost`"]],
        'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
    ]));

    $inventory = $inspector->inspect([new MysqlCredential('admin', 'x', isAdmin: true)]);

    assertTrue($inventory->complete);
    assertContains('Couverture complete', $inventory->coverageNote());
});

test("« GRANT USAGE ON *.* » ne rend pas la couverture complete", function (): void {
    /*
     * Le bug qui coutait le plus cher. USAGE est le privilege vide : tout
     * utilisateur MySQL le porte, y compris celui d'un mutualise qui ne voit
     * qu'une seule base. Ne regarder que la portee « *.* » faisait donc
     * declarer l'inventaire complet a un compte qui voyait 13 bases sur 45 —
     * et l'outil annoncait « 0 orpheline » avec assurance, faisant conclure
     * qu'il n'y avait rien a nettoyer alors que 32 bases dormaient la.
     */
    $inspector = new DatabaseInspector(fn () => new FakeGateway('u1_a@localhost', [
        'SHOW GRANTS' => [
            ['Grants' => "GRANT USAGE ON *.* TO `u1_a`@`%`"],
            ['Grants' => "GRANT ALL PRIVILEGES ON `u1_blog`.* TO `u1_a`@`%`"],
        ],
        'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
    ]));

    $inventaire = $inspector->inspect([new MysqlCredential('u1_a', 'x')]);

    assertFalse($inventaire->complete, 'USAGE n\'autorise que la connexion, rien d\'autre');
    assertContains('Couverture partielle', $inventaire->coverageNote());
});

test("Un SELECT global suffit a enumerer les bases", function (): void {
    // A l'inverse, un privilege qui permet vraiment de lire les schemas rend
    // bien la vue complete : refuser celui-la priverait d'une reponse fiable
    // les comptes qui l'ont.
    foreach (['SELECT', 'SHOW DATABASES', 'SELECT, INSERT, UPDATE'] as $privilege) {
        $inspector = new DatabaseInspector(fn () => new FakeGateway('lecteur@localhost', [
            'SHOW GRANTS' => [['Grants' => "GRANT {$privilege} ON *.* TO `lecteur`@`%`"]],
            'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
        ]));

        assertTrue(
            $inspector->inspect([new MysqlCredential('lecteur', 'x')])->complete,
            "« {$privilege} ON *.* » permet d'enumerer les schemas"
        );
    }
});

test("Un acces limite a une base ne rend pas la couverture complete", function (): void {
    $inspector = new DatabaseInspector(fn () => new FakeGateway('u1_a@localhost', [
        'SHOW GRANTS' => [['Grants' => "GRANT ALL PRIVILEGES ON `u1_blog`.* TO `u1_a`@`localhost`"]],
        'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
    ]));

    assertFalse($inspector->inspect([new MysqlCredential('u1_a', 'x')])->complete);
});

test("L'inspecteur se rabat sur SHOW DATABASES si information_schema est refuse", function (): void {
    $inspector = new DatabaseInspector(fn () => new FakeGateway('u1@localhost', [
        'information_schema.SCHEMATA' => new MysqlException('access denied'),
        'SHOW DATABASES' => [['Database' => 'u1_blog'], ['Database' => 'mysql']],
    ]));

    assertSame(['u1_blog'], $inspector->inspect([new MysqlCredential('u1', 'x')])->names());
});

test("Un acces en echec est trace sans interrompre le scan", function (): void {
    $queue = [
        new FakeGateway('casse@localhost', [
            'information_schema.SCHEMATA' => new MysqlException("Access denied for user 'casse'"),
            'SHOW DATABASES' => new MysqlException("Access denied for user 'casse'"),
        ]),
        new FakeGateway('bon@localhost', [
            'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
        ]),
    ];

    $inspector = new DatabaseInspector(function () use (&$queue): MysqlGateway {
        return array_shift($queue);
    });

    $inventory = $inspector->inspect([
        new MysqlCredential('casse', 'x', source: 'domains/vieux/wp-config.php'),
        new MysqlCredential('bon', 'y'),
    ]);

    assertSame(['u1_blog'], $inventory->names());
    assertCount(1, $inventory->failures());
    assertContains('Access denied', $inventory->failures()[0]->error ?? '');
    assertSame('domains/vieux/wp-config.php', $inventory->failures()[0]->source);
});

test('Les acces en double ne sont interroges qu une fois', function (): void {
    $calls = 0;
    $inspector = new DatabaseInspector(function () use (&$calls): MysqlGateway {
        $calls++;

        return new FakeGateway('u1@localhost', [
            'information_schema.SCHEMATA' => [['name' => 'u1_blog', 'charset' => null, 'collation' => null]],
        ]);
    });

    // Trois sites partageant les memes identifiants : une seule connexion.
    $inspector->inspect([
        new MysqlCredential('u1', 'secret', source: 'a/wp-config.php'),
        new MysqlCredential('u1', 'secret', source: 'b/wp-config.php'),
        new MysqlCredential('u1', 'secret', source: 'c/.env'),
    ]);

    assertSame(1, $calls);
});
