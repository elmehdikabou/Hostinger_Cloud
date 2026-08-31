<?php

declare(strict_types=1);

namespace HostingerSpace\Detector\App;

use HostingerSpace\Detector\Detection;
use HostingerSpace\Detector\Detector;
use HostingerSpace\Detector\DiscoveredDatabase;
use HostingerSpace\Detector\Parse;
use HostingerSpace\Detector\SiteContext;

/**
 * Dolibarr — ERP/CRM, tres repandu chez les petites structures.
 *
 * Sans ce detecteur, un Dolibarr en service voyait sa base comptee comme
 * orpheline : sa configuration ne ressemble a aucune autre, ni « define » ni
 * tableau, mais des variables prefixees « dolibarr_main_ », et elle vit dans
 * un conf/conf.php que rien d'autre ne lit. La base ressortait donc comme
 * utilisee par personne — le faux positif exact qui fait supprimer une base
 * pleine.
 */
final class Dolibarr implements Detector
{
    /**
     * Le fichier de configuration, selon que la racine web soit htdocs ou son
     * parent. Les deux se rencontrent : Hostinger sert souvent le dossier
     * choisi a la creation du sous-domaine, htdocs ou non.
     *
     * @var array<int,string>
     */
    private const CONFIGS = [
        'conf/conf.php',
        'htdocs/conf/conf.php',
    ];

    /**
     * Ce qui atteste une racine Dolibarr, et non un simple dossier voisin.
     *
     * Sans cette exigence, le dossier conf/ lui-meme ressortait en second
     * site : il voit le meme conf.php, et l'espace se remplissait de doublons
     * qui ne correspondent a rien.
     *
     * @var array<int,string>
     */
    private const MARKERS = ['filefunc.inc.php', 'main.inc.php', 'core', 'htdocs'];

    public function priority(): int
    {
        // Avant le detecteur generique, qui prendrait conf.php pour un fichier
        // de connexion quelconque et n'en tirerait rien.
        return 30;
    }

    public function detect(SiteContext $context): ?Detection
    {
        if (!$context->hasAny(...self::MARKERS)) {
            return null;
        }

        $config = $context->readFirst(...self::CONFIGS);

        if ($config === null || !str_contains($config->contents, 'dolibarr_main_')) {
            return null;
        }

        $values = Parse::phpVariables($config->contents);
        $notes = [];
        $databases = [];
        $driver = strtolower($values['dolibarr_main_db_type'] ?? 'mysqli');

        if (!str_contains($driver, 'mysql')) {
            $notes[] = "Pilote « {$driver} » : ce Dolibarr n'utilise pas MySQL.";
        } elseif (trim($values['dolibarr_main_db_name'] ?? '') === '') {
            $notes[] = 'conf.php lu, mais $dolibarr_main_db_name est vide.';
        } else {
            [$host, $port] = Parse::hostAndPort($values['dolibarr_main_db_host'] ?? 'localhost');

            $databases[] = new DiscoveredDatabase(
                database: $values['dolibarr_main_db_name'],
                user: $values['dolibarr_main_db_user'] ?? null,
                password: $values['dolibarr_main_db_pass'] ?? null,
                host: $host,
                // Le port est une variable a part, et vide veut dire « defaut ».
                port: trim($values['dolibarr_main_db_port'] ?? '') !== ''
                    ? (int) $values['dolibarr_main_db_port']
                    : $port,
                tablePrefix: $values['dolibarr_main_db_prefix'] ?? null,
                sourceFile: $config->path,
            );
        }

        // Le dossier des documents contient factures, pieces jointes et
        // sauvegardes. Servi par le web, il expose la comptabilite entiere.
        $documents = $values['dolibarr_main_data_root'] ?? '';

        if ($documents !== '' && !str_contains($documents, 'documents')) {
            $notes[] = "Dossier de donnees inhabituel : {$documents}";
        }

        return new Detection(
            app: 'dolibarr',
            label: 'Dolibarr',
            version: $this->version($context, $config->path),
            databases: $databases,
            notes: $notes,
            evidence: $config->path,
        );
    }

    private function version(SiteContext $context, string $configPath): ?string
    {
        // filefunc.inc.php porte la version courante, a cote du conf/.
        $base = str_replace('conf/conf.php', '', $configPath);

        foreach ([$base . 'filefunc.inc.php', $base . 'includes/filefunc.inc.php'] as $candidate) {
            $source = $context->read($candidate, 65_536);

            if ($source === null) {
                continue;
            }

            if (preg_match("/DOL_VERSION'\s*,\s*'([0-9][^']*)'/", $source, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
