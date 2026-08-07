<?php

declare(strict_types=1);

namespace HostingerSpace\Network;

/**
 * Date d'expiration et bureau d'enregistrement d'un nom de domaine, via RDAP.
 *
 * RDAP est le successeur structure de WHOIS : reponse en JSON, pas de texte
 * libre a analyser au petit bonheur. rdap.org redirige vers le registre
 * competent selon l'extension.
 */
final class RdapProbe
{
    /** @var array<string,RdapResult> */
    private array $cache = [];

    public function __construct(private readonly int $timeout = 8)
    {
    }

    public function check(string $domain): RdapResult
    {
        $registrable = self::registrableDomain($domain);

        if ($registrable === null) {
            return new RdapResult(null, null, "Domaine non interrogeable.");
        }

        // Plusieurs sous-domaines partagent le meme nom de domaine : une seule
        // requete suffit pour tous.
        return $this->cache[$registrable] ??= $this->fetch($registrable);
    }

    private function fetch(string $domain): RdapResult
    {
        if (!extension_loaded('curl')) {
            return new RdapResult(null, null, "Extension curl absente.");
        }

        $handle = curl_init('https://rdap.org/domain/' . rawurlencode($domain));

        if ($handle === false) {
            return new RdapResult(null, null, "Requete impossible.");
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
            CURLOPT_USERAGENT => 'hspace/1.0 (inventaire d\'hebergement)',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status !== 200) {
            return new RdapResult(null, null, match (true) {
                $status === 404 => "Domaine inconnu du registre (expire, ou extension sans RDAP public).",
                $status === 0 => "Service RDAP injoignable.",
                default => "Le service RDAP a repondu {$status}.",
            });
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new RdapResult(null, null, "Reponse RDAP illisible.");
        }

        if (!is_array($data)) {
            return new RdapResult(null, null, "Reponse RDAP inattendue.");
        }

        return new RdapResult($this->expiration($data), $this->registrar($data), null);
    }

    /** @param array<string,mixed> $data */
    private function expiration(array $data): ?int
    {
        foreach ($data['events'] ?? [] as $event) {
            if (!is_array($event) || ($event['eventAction'] ?? null) !== 'expiration') {
                continue;
            }

            $timestamp = strtotime((string) ($event['eventDate'] ?? ''));

            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function registrar(array $data): ?string
    {
        foreach ($data['entities'] ?? [] as $entity) {
            if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            // vCard : ['vcard', [['version',...], ['fn', {}, 'text', 'Nom'], ...]]
            foreach ($entity['vcardArray'][1] ?? [] as $field) {
                if (is_array($field) && ($field[0] ?? null) === 'fn' && isset($field[3])) {
                    return (string) $field[3];
                }
            }
        }

        return null;
    }

    /**
     * Reduit un hote au nom de domaine enregistrable.
     *
     * Sans liste publique des suffixes, on retient les deux derniers
     * segments, sauf pour les extensions a deux niveaux les plus courantes
     * ou il en faut trois (« exemple.co.uk »).
     */
    public static function registrableDomain(string $host): ?string
    {
        $host = strtolower(trim($host, ". \t\n"));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        $twoLevel = [
            'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'net.uk', 'sch.uk',
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
            'com.br', 'net.br', 'org.br',
            'co.jp', 'or.jp', 'ne.jp', 'ac.jp',
            'co.nz', 'net.nz', 'org.nz',
            'com.mx', 'com.ar', 'com.tr', 'com.cn', 'com.tw', 'com.sg', 'com.hk',
            'co.za', 'co.in', 'net.in', 'org.in', 'co.il', 'co.kr', 'co.id',
            'com.ma', 'net.ma', 'org.ma', 'gov.ma', 'ac.ma', 'press.ma',
        ];

        $lastTwo = implode('.', array_slice($labels, -2));

        if (in_array($lastTwo, $twoLevel, true)) {
            return count($labels) >= 3 ? implode('.', array_slice($labels, -3)) : null;
        }

        return $lastTwo;
    }
}
