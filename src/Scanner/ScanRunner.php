<?php

declare(strict_types=1);

namespace HostingerSpace\Scanner;

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Config;
use HostingerSpace\Model\Site;
use HostingerSpace\Mysql\DatabaseInfo;
use HostingerSpace\Mysql\DatabaseInspector;
use HostingerSpace\Mysql\DatabaseInventory;
use HostingerSpace\Mysql\GatewayFactory;
use HostingerSpace\Mysql\KnownDatabases;
use HostingerSpace\Mysql\MysqlCredential;
use HostingerSpace\Network\HttpProbe;
use HostingerSpace\Network\RdapProbe;
use HostingerSpace\Network\SslProbe;
use HostingerSpace\Transport\Transport;

/**
 * Deroule un scan complet, du parcours des dossiers jusqu'aux constats.
 */
final class ScanRunner
{
    /** @var \Closure(string, string): void */
    private \Closure $report;

    /** @var array<int,string> */
    private array $errors = [];

    /**
     * @param (callable(string, string): void)|null $reporter Recoit (etape, message).
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        ?callable $reporter = null,
    ) {
        $this->report = $reporter !== null
            ? $reporter(...)
            : static function (string $step, string $message): void {
            };
    }

    public function run(): ScanResult
    {
        $startedAt = time();

        ($this->report)('connexion', "Connexion a {$this->transport->label()}");
        $this->transport->connect();

        $sites = $this->discoverSites();
        $this->measure($sites);
        $inventory = $this->inspectDatabases($sites);
        $this->probeNetwork($sites);

        ($this->report)('analyse', 'Rapprochement des sites et des bases');

        $analysis = (new Linker($this->config->int('analysis.abandoned_after_days', 180)))
            ->analyse($sites, $inventory);

        return new ScanResult(
            startedAt: $startedAt,
            finishedAt: time(),
            host: $this->transport->label(),
            mode: $this->config->mode(),
            sites: $sites,
            inventory: $inventory,
            analysis: $analysis,
            errors: $this->errors,
        );
    }

    /** @return array<int,Site> */
    private function discoverSites(): array
    {
        ($this->report)('sites', 'Recherche des sites');

        $scanner = new SiteScanner($this->transport, $this->config);
        $sites = $scanner->scan(function (string $name): void {
            ($this->report)('site', $name);
        });

        ($this->report)('sites', count($sites) . ' site(s) trouve(s)');

        return $sites;
    }

    /** @param array<int,Site> $sites */
    private function measure(array $sites): void
    {
        if ($sites === []) {
            return;
        }

        ($this->report)('mesure', 'Mesure des tailles et des dates de derniere activite');

        $stats = (new FilesystemStats($this->transport, $this->config->stringList('paths.ignore')))
            ->measure(array_map(static fn (Site $s): string => $s->path, $sites));

        foreach ($sites as $site) {
            $measured = $stats[$site->path] ?? null;

            if ($measured === null) {
                continue;
            }

            $site->sizeBytes = $measured['size'];
            $site->fileCount = $measured['files'];
            $site->lastModifiedAt = $measured['mtime'];
        }
    }

    /** @param array<int,Site> $sites */
    private function inspectDatabases(array $sites): DatabaseInventory
    {
        ($this->report)('mysql', 'Inventaire des bases de donnees');

        $credentials = $this->credentials($sites);

        if ($credentials === []) {
            // Meme sans le moindre acces MySQL, la liste declaree depuis
            // hPanel suffit a rattacher les bases aux sites.
            $inventory = $this->mergeDeclaredList(DatabaseInventory::empty());

            if ($inventory->databases === []) {
                $this->errors[] = "Aucun acces MySQL et aucune liste declaree : aucune base n'a pu etre listee. " .
                    "Renseigne « mysql.admin_user », ou colle ta liste avec « php bin/hspace import-databases ».";
            }

            return $inventory;
        }

        $inventory = (new DatabaseInspector((new GatewayFactory($this->transport))->asCallable()))
            ->inspect($credentials);

        foreach ($inventory->failures() as $failure) {
            $this->errors[] = "Acces MySQL refuse pour {$failure->label} (source : {$failure->source}) : {$failure->error}";
        }

        $inventory = $this->mergeDeclaredList($inventory);

        ($this->report)('mysql', count($inventory->databases) . ' base(s) connue(s)');

        return $inventory;
    }

    /**
     * Complete l'inventaire avec la liste declaree depuis hPanel.
     *
     * Quand chaque base a son propre utilisateur — le cas courant chez
     * Hostinger — aucun compte ne les voit toutes, et l'inventaire par MySQL
     * reste incomplet quoi qu'on fasse. Mais decider qu'une base ne sert a
     * personne ne demande pas de l'ouvrir : il suffit de connaitre son nom.
     * La liste collee depuis hPanel rend donc la question decidable.
     */
    private function mergeDeclaredList(DatabaseInventory $inventory): DatabaseInventory
    {
        $declared = KnownDatabases::fromFile($this->config->string('mysql.known_databases_file'));

        if ($declared === []) {
            return $inventory;
        }

        $databases = $inventory->databases;
        $added = 0;

        foreach ($declared as $name) {
            if (!isset($databases[$name])) {
                $databases[$name] = new DatabaseInfo($name);
                $added++;
            }

            $databases[$name]->addSource('liste hPanel');
        }

        ksort($databases, SORT_NATURAL | SORT_FLAG_CASE);

        ($this->report)('mysql', count($declared) . ' base(s) declaree(s) depuis hPanel'
            . ($added > 0 ? ", dont {$added} qu'aucun acces MySQL ne voyait" : ''));

        return new DatabaseInventory($databases, $inventory->probes, complete: true, declared: true);
    }

    /**
     * Rassemble tous les acces MySQL utilisables : celui de la configuration,
     * puis ceux lus dans les sites. Plus il y en a, plus la vue est complete.
     *
     * @param array<int,Site> $sites
     *
     * @return array<int,MysqlCredential>
     */
    private function credentials(array $sites): array
    {
        $credentials = [];
        $admin = $this->config->string('mysql.admin_user');

        if ($admin !== null && trim($admin) !== '') {
            $credentials[] = new MysqlCredential(
                user: $admin,
                password: (string) $this->config->string('mysql.admin_password', ''),
                host: (string) $this->config->string('mysql.host', 'localhost'),
                port: $this->config->int('mysql.port', 3306),
                source: 'config',
                isAdmin: true,
            );
        }

        if (!$this->config->bool('mysql.use_discovered_credentials', true)) {
            return $credentials;
        }

        foreach ($sites as $site) {
            foreach ($site->discovered as $discovered) {
                $credential = $discovered->toCredential($site->key . '/' . $discovered->sourceFile);

                if ($credential !== null) {
                    $credentials[] = $credential;
                }
            }
        }

        return $credentials;
    }

    /** @param array<int,Site> $sites */
    private function probeNetwork(array $sites): void
    {
        $checkHttp = $this->config->bool('analysis.check_http', true);
        $checkSsl = $this->config->bool('analysis.check_ssl', true);
        $checkDomain = $this->config->bool('analysis.check_domain_expiry', true);

        if (!$checkHttp && !$checkSsl && !$checkDomain) {
            return;
        }

        ($this->report)('reseau', 'Verification des domaines (HTTP, SSL, expiration)');

        $timeout = $this->config->int('analysis.network_timeout', 8);
        $http = new HttpProbe($timeout);
        $ssl = new SslProbe($timeout);
        $rdap = new RdapProbe($timeout);

        // Les sites imbriques partagent le domaine de leur parent : inutile
        // d'interroger cinq fois le meme nom.
        $done = [];

        foreach ($sites as $site) {
            if (!$this->isRealDomain($site->domain)) {
                continue;
            }

            if (isset($done[$site->domain])) {
                $this->copyNetworkResults($sites, $done[$site->domain], $site);

                continue;
            }

            $done[$site->domain] = $site;
            ($this->report)('domaine', $site->domain);

            if ($checkHttp) {
                $result = $http->check($site->domain);
                $site->httpStatus = $result->status;
                $site->httpNote = $result->note;
                $site->finalUrl = $result->finalUrl;
            }

            if ($checkSsl) {
                $result = $ssl->check($site->domain);
                $site->sslExpiresAt = $result->expiresAt;
                $site->sslIssuer = $result->issuer;
                $site->sslNote = $result->note;
            }

            if ($checkDomain) {
                $result = $rdap->check($site->domain);
                $site->domainExpiresAt = $result->expiresAt;
                $site->registrar = $result->registrar;
            }
        }
    }

    /** @param array<int,Site> $sites */
    private function copyNetworkResults(array $sites, Site $from, Site $to): void
    {
        $to->httpStatus = $from->httpStatus;
        $to->httpNote = $from->httpNote;
        $to->finalUrl = $from->finalUrl;
        $to->sslExpiresAt = $from->sslExpiresAt;
        $to->sslIssuer = $from->sslIssuer;
        $to->sslNote = $from->sslNote;
        $to->domainExpiresAt = $from->domainExpiresAt;
        $to->registrar = $from->registrar;
    }

    /**
     * « domaine principal » ou un dossier local ne sont pas des noms
     * interrogeables : les verifier ferait perdre un delai reseau par site.
     */
    private function isRealDomain(string $domain): bool
    {
        return str_contains($domain, '.')
            && !str_contains($domain, ' ')
            && preg_match('/^[a-z0-9.-]+$/i', $domain) === 1;
    }
}
