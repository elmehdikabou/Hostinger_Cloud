<?php

declare(strict_types=1);

namespace HostingerSpace\Analysis;

use HostingerSpace\Model\DatabaseLink;
use HostingerSpace\Model\Finding;
use HostingerSpace\Model\FindingKind;
use HostingerSpace\Model\LinkState;
use HostingerSpace\Model\Severity;
use HostingerSpace\Model\Site;
use HostingerSpace\Mysql\DatabaseInventory;

/**
 * Rapproche ce que declarent les sites de ce qu'expose le serveur MySQL.
 *
 * C'est ici que se decide ce que l'utilisateur va lire, donc ici qu'il faut
 * etre prudent : une base annoncee « orpheline » a tort peut etre supprimee
 * et emporter un site avec elle. Chaque conclusion est donc conditionnee a ce
 * qu'on a reellement pu observer, et la severite baisse des qu'un doute
 * subsiste — configuration de site illisible, ou acces MySQL partiel.
 */
final class Linker
{
    /**
     * Versions majeures dont le support est termine.
     *
     * Ce sont des faits acquis, pas une comparaison a « la derniere version » :
     * la table ne se perime donc pas dans le mauvais sens. Elle merite une
     * relecture annuelle pour y ajouter les branches nouvellement en fin de vie.
     *
     * @var array<string,array{version:string,label:string}>
     */
    private const END_OF_LIFE = [
        'wordpress' => ['version' => '5.0', 'label' => 'WordPress 4.x'],
        'joomla' => ['version' => '4.0', 'label' => 'Joomla 3.x'],
        'drupal' => ['version' => '9.0', 'label' => 'Drupal 7 et 8'],
        'prestashop' => ['version' => '1.7', 'label' => 'PrestaShop 1.6'],
        'laravel' => ['version' => '9.0', 'label' => 'Laravel 8 et anterieurs'],
        'magento' => ['version' => '2.4', 'label' => 'Magento 2.3 et anterieurs'],
    ];

    public function __construct(private readonly int $abandonedAfterDays = 180)
    {
    }

    /**
     * @param array<int,Site> $sites
     */
    public function analyse(array $sites, DatabaseInventory $inventory, ?int $now = null): Analysis
    {
        $now ??= time();
        $links = [];
        $findings = [];
        $usage = [];
        $sitesFullyRead = true;

        foreach ($sites as $site) {
            if ($site->discovered === [] && $site->expectsDatabase()) {
                $sitesFullyRead = false;
            }

            foreach ($site->discovered as $discovered) {
                if (!$discovered->named()) {
                    $sitesFullyRead = false;

                    continue;
                }

                $state = $this->stateFor($discovered->isLocal(), $discovered->database, $inventory);

                $links[] = new DatabaseLink(
                    siteKey: $site->key,
                    databaseName: $discovered->database,
                    state: $state,
                    user: $discovered->user,
                    host: $discovered->host,
                    port: $discovered->port,
                    tablePrefix: $discovered->tablePrefix,
                    sourceFile: $discovered->sourceFile,
                    connectionName: $discovered->connectionName,
                );

                if ($state === LinkState::Linked || $state === LinkState::Unverifiable) {
                    $usage[$discovered->database][] = $site->key;
                }
            }
        }

        $findings = array_merge(
            $findings,
            $this->siteFindings($sites, $links, $now),
            $this->databaseFindings($sites, $links, $inventory, $usage, $sitesFullyRead, $now),
        );

        $orphans = array_values(array_filter(
            $inventory->names(),
            static fn (string $name): bool => ($usage[$name] ?? []) === []
        ));

        if (!$inventory->complete) {
            $findings[] = new Finding(
                kind: FindingKind::PartialCoverage,
                severity: Severity::Info,
                title: "L'inventaire des bases est partiel",
                detail: $inventory->coverageNote(),
                subjectType: 'scan',
                subject: 'couverture',
                actions: [
                    "Cree dans hPanel un utilisateur MySQL rattache a toutes tes bases, puis renseigne-le dans « mysql.admin_user ».",
                ],
            );
        }

        return new Analysis($links, $findings, $orphans, $usage, $sitesFullyRead);
    }

    private function stateFor(bool $isLocal, string $name, DatabaseInventory $inventory): LinkState
    {
        if (!$isLocal) {
            return LinkState::External;
        }

        if ($inventory->get($name) !== null) {
            return LinkState::Linked;
        }

        // Sans vue complete du serveur, l'absence d'une base de notre liste ne
        // prouve pas qu'elle n'existe pas : on ne declare pas le site casse.
        return $inventory->complete ? LinkState::Missing : LinkState::Unverifiable;
    }

    /**
     * @param array<int,Site>         $sites
     * @param array<int,DatabaseLink> $links
     *
     * @return array<int,Finding>
     */
    private function siteFindings(array $sites, array $links, int $now): array
    {
        $findings = [];
        $linksBySite = [];

        foreach ($links as $link) {
            $linksBySite[$link->siteKey][] = $link;
        }

        foreach ($sites as $site) {
            $siteLinks = $linksBySite[$site->key] ?? [];

            foreach ($siteLinks as $link) {
                if ($link->state === LinkState::Missing) {
                    $findings[] = new Finding(
                        kind: FindingKind::MissingDatabase,
                        severity: Severity::Critical,
                        title: "{$site->displayName()} pointe vers une base qui n'existe pas",
                        detail: "Le site declare la base « {$link->databaseName} » dans {$link->sourceFile}, " .
                            "mais aucune base de ce nom n'existe sur le serveur MySQL. Le site est probablement " .
                            "hors service, ou sa base a ete supprimee.",
                        subjectType: 'site',
                        subject: $site->key,
                        actions: [
                            "Ouvrir {$site->displayName()} dans un navigateur pour confirmer l'erreur.",
                            "Verifier dans hPanel si une base au nom proche existe (renommage, restauration partielle).",
                            "Si le site n'a plus lieu d'etre, l'archiver puis supprimer son dossier.",
                        ],
                    );
                }

                if ($link->state === LinkState::External) {
                    $findings[] = new Finding(
                        kind: FindingKind::ExternalDatabase,
                        severity: Severity::Info,
                        title: "{$site->displayName()} utilise une base hors Hostinger",
                        detail: "La base « {$link->databaseName} » est hebergee sur {$link->host}. " .
                            "Elle n'entre pas dans cet inventaire, et sa disponibilite ne depend pas de ton hebergement.",
                        subjectType: 'site',
                        subject: $site->key,
                    );
                }
            }

            if ($siteLinks === [] && $site->expectsDatabase()) {
                $findings[] = new Finding(
                    kind: FindingKind::SiteWithoutDatabase,
                    severity: Severity::Warning,
                    title: "{$site->displayName()} : aucune base rattachee",
                    detail: "Le site a ete identifie comme « {$site->describe()} » mais aucune base n'a pu etre " .
                        "lue dans sa configuration. Tant que ce point n'est pas leve, une base qu'il utilise " .
                        "peut apparaitre a tort comme orpheline.",
                    subjectType: 'site',
                    subject: $site->key,
                    actions: ["Verifier a la main le fichier de configuration de ce site."],
                );
            }

            if ($site->app === 'empty') {
                $findings[] = new Finding(
                    kind: FindingKind::EmptyDirectory,
                    severity: Severity::Info,
                    title: "{$site->displayName()} : dossier vide",
                    detail: "Aucun contenu dans la racine servie. Domaine reserve mais jamais publie, ou site supprime dont le dossier subsiste.",
                    subjectType: 'site',
                    subject: $site->key,
                );
            }

            if (in_array($site->app, ['phpmyadmin', 'adminer'], true)) {
                $findings[] = new Finding(
                    kind: FindingKind::ExposedAdminTool,
                    severity: Severity::Critical,
                    title: "{$site->appLabel} accessible sur {$site->displayName()}",
                    detail: "Un outil d'administration de bases est accessible publiquement a cette adresse. " .
                        "S'il n'est pas protege, c'est un acces direct a tes donnees.",
                    subjectType: 'site',
                    subject: $site->key,
                    actions: [
                        "Le supprimer s'il ne sert plus.",
                        "Sinon le proteger par mot de passe (.htpasswd) ou le restreindre par adresse IP.",
                        "Utiliser plutot le phpMyAdmin integre a hPanel, qui n'est pas expose publiquement.",
                    ],
                );
            }

            foreach ($site->notes as $note) {
                if (str_contains($note, 'WP_DEBUG') || str_contains($note, 'APP_DEBUG')) {
                    $findings[] = new Finding(
                        kind: FindingKind::DebugEnabled,
                        severity: Severity::Warning,
                        title: "{$site->displayName()} : mode debogage actif",
                        detail: $note,
                        subjectType: 'site',
                        subject: $site->key,
                        actions: ["Desactiver le debogage sur un site en production."],
                    );
                }
            }

            $eol = $this->endOfLife($site);

            if ($eol !== null) {
                $findings[] = new Finding(
                    kind: FindingKind::OutdatedApp,
                    severity: Severity::Warning,
                    title: "{$site->displayName()} : {$eol} n'est plus maintenu",
                    detail: "Le site tourne en {$site->describe()}. Cette branche ne recoit plus de correctifs " .
                        "de securite : les failles connues n'y seront pas corrigees.",
                    subjectType: 'site',
                    subject: $site->key,
                    actions: ["Planifier une montee de version, ou archiver le site s'il n'est plus utile."],
                );
            }

            foreach ($this->networkFindings($site, $now) as $finding) {
                $findings[] = $finding;
            }

            $abandoned = $this->abandonmentDetail($site, $siteLinks, $now);

            if ($abandoned !== null) {
                $findings[] = new Finding(
                    kind: FindingKind::AbandonedSite,
                    severity: Severity::Info,
                    title: "{$site->displayName()} : aucune activite recente",
                    detail: $abandoned,
                    subjectType: 'site',
                    subject: $site->key,
                    actions: [
                        "Si le site n'a plus d'usage : sauvegarder fichiers et base, puis liberer l'espace.",
                        "S'il sert encore, verifier qu'il est a jour — un site inactif reste une surface d'attaque.",
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * Constats issus des verifications reseau.
     *
     * Un site peut etre impeccable sur le disque et pourtant injoignable :
     * certificat expire, domaine non renouvele, DNS qui pointe ailleurs. Ces
     * pannes-la ne se voient qu'en interrogeant le domaine de l'exterieur.
     *
     * @return array<int,Finding>
     */
    private function networkFindings(Site $site, int $now): array
    {
        // Un site imbrique herite du domaine de son parent : signaler deux
        // fois le meme certificat n'apporterait rien.
        if ($site->isNested()) {
            return [];
        }

        $findings = [];

        if ($site->httpStatus !== null && $site->httpStatus >= 400) {
            $findings[] = new Finding(
                kind: FindingKind::HttpError,
                severity: $site->httpStatus >= 500 ? Severity::Critical : Severity::Warning,
                title: "{$site->domain} repond {$site->httpStatus}",
                detail: $site->httpNote ?? "Le domaine renvoie une erreur HTTP {$site->httpStatus}.",
                subjectType: 'site',
                subject: $site->key,
            );
        } elseif ($site->httpStatus === null && $site->httpNote !== null) {
            $findings[] = new Finding(
                kind: FindingKind::HttpError,
                severity: Severity::Warning,
                title: "{$site->domain} est injoignable",
                detail: $site->httpNote,
                subjectType: 'site',
                subject: $site->key,
            );
        } elseif ($site->httpNote !== null) {
            $findings[] = new Finding(
                kind: FindingKind::HttpError,
                severity: Severity::Info,
                title: "{$site->domain} : ce que voit un visiteur",
                detail: $site->httpNote,
                subjectType: 'site',
                subject: $site->key,
            );
        }

        if ($site->sslNote !== null) {
            $findings[] = new Finding(
                kind: FindingKind::SslProblem,
                severity: Severity::Warning,
                title: "{$site->domain} : certificat a verifier",
                detail: $site->sslNote,
                subjectType: 'site',
                subject: $site->key,
                actions: ["Regenerer le certificat depuis hPanel > SSL."],
            );
        }

        if ($site->sslExpiresAt !== null) {
            $days = (int) floor(($site->sslExpiresAt - $now) / 86_400);

            if ($days < 21) {
                $findings[] = new Finding(
                    kind: FindingKind::SslExpiring,
                    severity: $days < 7 ? Severity::Critical : Severity::Warning,
                    title: $days < 0
                        ? "{$site->domain} : certificat expire depuis " . abs($days) . ' jours'
                        : "{$site->domain} : certificat expire dans {$days} jours",
                    detail: $days < 0
                        ? "Les navigateurs affichent un avertissement de securite avant meme d'ouvrir le site."
                        : "Le renouvellement est normalement automatique chez Hostinger ; s'il n'a pas eu lieu " .
                            "a moins de trois semaines de l'echeance, c'est qu'il bloque.",
                    subjectType: 'site',
                    subject: $site->key,
                    actions: ["Verifier l'etat du certificat dans hPanel > SSL, et le regenerer au besoin."],
                );
            }
        }

        if ($site->domainExpiresAt !== null) {
            $days = (int) floor(($site->domainExpiresAt - $now) / 86_400);

            if ($days < 45) {
                $findings[] = new Finding(
                    kind: FindingKind::DomainExpiring,
                    severity: $days < 14 ? Severity::Critical : Severity::Warning,
                    title: $days < 0
                        ? "Le domaine {$site->domain} a expire il y a " . abs($days) . ' jours'
                        : "Le domaine {$site->domain} expire dans {$days} jours",
                    detail: "Un domaine non renouvele cesse de fonctionner puis redevient disponible a l'achat. " .
                        ($site->registrar !== null ? "Bureau d'enregistrement : {$site->registrar}." : ''),
                    subjectType: 'site',
                    subject: $site->key,
                    actions: ["Renouveler le domaine, ou activer le renouvellement automatique."],
                );
            }
        }

        return $findings;
    }

    private function endOfLife(Site $site): ?string
    {
        $rule = self::END_OF_LIFE[$site->app] ?? null;

        if ($rule === null || $site->version === null) {
            return null;
        }

        return version_compare($site->version, $rule['version'], '<') ? $rule['label'] : null;
    }

    /**
     * @param array<int,DatabaseLink> $siteLinks
     */
    private function abandonmentDetail(Site $site, array $siteLinks, int $now): ?string
    {
        if ($site->lastModifiedAt === null || !$site->expectsDatabase()) {
            return null;
        }

        $threshold = $now - ($this->abandonedAfterDays * 86_400);

        if ($site->lastModifiedAt >= $threshold) {
            return null;
        }

        $days = (int) floor(($now - $site->lastModifiedAt) / 86_400);
        $detail = "Aucun fichier modifie depuis {$days} jours (hors caches et dependances).";

        // Un site peut ne plus recevoir de fichiers tout en restant actif :
        // un blog qui publie ecrit en base, pas sur le disque. La date
        // d'ecriture en base est donc le contre-indice a verifier.
        foreach ($siteLinks as $link) {
            if ($link->state !== LinkState::Linked) {
                continue;
            }

            return $detail . " La base « {$link->databaseName} » reste a verifier : " .
                "si elle recoit encore des ecritures, le site est vivant malgre des fichiers figes.";
        }

        return $detail;
    }

    /**
     * @param array<int,Site>                 $sites
     * @param array<int,DatabaseLink>         $links
     * @param array<string,array<int,string>> $usage
     *
     * @return array<int,Finding>
     */
    private function databaseFindings(
        array $sites,
        array $links,
        DatabaseInventory $inventory,
        array $usage,
        bool $sitesFullyRead,
        int $now,
    ): array {
        $findings = [];
        $byKey = [];

        foreach ($sites as $site) {
            $byKey[$site->key] = $site;
        }

        foreach ($inventory->databases as $name => $info) {
            $users = $usage[$name] ?? [];

            if ($users === []) {
                $findings[] = $this->orphanFinding($name, $info->sizeBytes, $info->tableCount, $info->updatedAt, $sitesFullyRead, $inventory->complete, $now);

                continue;
            }

            if (count($users) > 1) {
                $findings[] = $this->sharedFinding($name, $users, $links, $byKey);
            }

            if ($info->isEmpty()) {
                $findings[] = new Finding(
                    kind: FindingKind::EmptyDatabase,
                    severity: Severity::Warning,
                    title: "La base « {$name} » est vide",
                    detail: "Elle ne contient aucune table, alors qu'un site la declare. " .
                        "Ce site ne peut pas fonctionner : installation jamais terminee, ou contenu perdu.",
                    subjectType: 'database',
                    subject: $name,
                );
            }
        }

        return $findings;
    }

    private function orphanFinding(
        string $name,
        int $sizeBytes,
        int $tableCount,
        ?int $updatedAt,
        bool $sitesFullyRead,
        bool $coverageComplete,
        int $now,
    ): Finding {
        $reasons = [];

        if (!$sitesFullyRead) {
            $reasons[] = "la configuration d'au moins un site n'a pas pu etre lue";
        }

        if (!$coverageComplete) {
            $reasons[] = "l'acces MySQL utilise ne voit pas forcement toutes les bases";
        }

        $certain = $reasons === [];
        $detail = "Aucun des sites analyses ne reference cette base.";

        if ($tableCount === 0) {
            $detail .= " Elle est en plus totalement vide : creation de test ou installation abandonnee.";
        } else {
            $detail .= sprintf(' Elle contient %d table(s) pour %s.', $tableCount, self::humanBytes($sizeBytes));
        }

        if ($updatedAt !== null) {
            $days = (int) floor(($now - $updatedAt) / 86_400);
            $detail .= " Derniere ecriture il y a environ {$days} jours.";
        }

        if (!$certain) {
            $detail .= ' Attention : ' . implode(', et ', $reasons) .
                ". Ce constat est donc une piste, pas une certitude.";
        }

        return new Finding(
            kind: FindingKind::OrphanDatabase,
            severity: match (true) {
                !$certain => Severity::Info,
                $tableCount === 0 => Severity::Info,
                default => Severity::Warning,
            },
            title: "La base « {$name} » n'est utilisee par aucun site",
            detail: $detail,
            subjectType: 'database',
            subject: $name,
            actions: array_values(array_filter([
                $certain ? null : "Lever d'abord le doute signale ci-dessus avant toute suppression.",
                "Sauvegarder avant tout : mysqldump -u UTILISATEUR -p {$name} > ~/sauvegarde-{$name}.sql",
                "Chercher le nom de la base dans tes fichiers, au cas ou un script hors site l'utiliserait : " .
                    "grep -rl {$name} ~/domains ~/public_html 2>/dev/null",
                "Une fois la sauvegarde verifiee, supprimer la base depuis hPanel > Bases de donnees MySQL.",
            ])),
        );
    }

    /**
     * @param array<int,string>       $users
     * @param array<int,DatabaseLink> $links
     * @param array<string,Site>      $byKey
     */
    private function sharedFinding(string $name, array $users, array $links, array $byKey): Finding
    {
        $prefixes = [];

        foreach ($links as $link) {
            if ($link->databaseName === $name && in_array($link->siteKey, $users, true)) {
                $prefixes[$link->siteKey] = $link->tablePrefix ?? '(aucun)';
            }
        }

        $names = array_map(
            static fn (string $key): string => $byKey[$key]?->displayName() ?? $key,
            $users
        );

        $distinctPrefixes = count(array_unique($prefixes)) === count($prefixes);

        // Des prefixes de tables differents, c'est une cohabitation voulue :
        // plusieurs sites dans une base pour economiser un quota. Des prefixes
        // identiques, c'est deux sites qui ecrivent dans les memes tables.
        $detail = $distinctPrefixes
            ? "Plusieurs sites partagent cette base avec des prefixes de tables distincts (" .
                implode(', ', array_map(static fn (string $k, string $p): string => "{$k} : {$p}", array_keys($prefixes), $prefixes)) .
                "). C'est une cohabitation volontaire, mais elle lie leur sort : supprimer la base les casse tous."
            : "Plusieurs sites utilisent cette base avec le meme prefixe de tables : ils ecrivent dans les memes " .
                "tables. Soit c'est un site duplique (preproduction, ancienne version), soit c'est un conflit.";

        return new Finding(
            kind: FindingKind::SharedDatabase,
            severity: $distinctPrefixes ? Severity::Info : Severity::Warning,
            title: "La base « {$name} » est partagee par " . count($users) . ' sites',
            detail: $detail . ' Sites concernes : ' . implode(', ', $names) . '.',
            subjectType: 'database',
            subject: $name,
        );
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 o';
        }

        $units = ['o', 'ko', 'Mo', 'Go', 'To'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return ($power === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0, ',', ' '))
            . ' ' . $units[$power];
    }
}
