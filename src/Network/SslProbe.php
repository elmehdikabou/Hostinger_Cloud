<?php

declare(strict_types=1);

namespace HostingerSpace\Network;

/**
 * Lit la date d'expiration du certificat presente par un domaine.
 *
 * On ouvre nous-memes la connexion TLS plutot que de passer par curl :
 * c'est le seul moyen de recuperer le certificat quand il est justement
 * invalide ou expire — precisement le cas qui nous interesse.
 */
final class SslProbe
{
    public function __construct(private readonly int $timeout = 8)
    {
    }

    public function check(string $domain): SslResult
    {
        if (!extension_loaded('openssl')) {
            return new SslResult(null, null, "Extension openssl absente : verification impossible.");
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // On veut lire le certificat meme s'il est expire ou mal
                // emis : la validation est faite ensuite, sur son contenu.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            return new SslResult(null, null, $this->explain($errorMessage ?: "code {$errorCode}"));
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return new SslResult(null, null, "Aucun certificat presente par le serveur.");
        }

        $parsed = openssl_x509_parse($certificate);

        if ($parsed === false) {
            return new SslResult(null, null, "Certificat illisible.");
        }

        $expiresAt = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null;
        $names = $this->subjectNames($parsed);
        $note = null;

        if ($names !== [] && !$this->covers($domain, $names)) {
            $note = "Le certificat ne couvre pas {$domain} (il couvre : " . implode(', ', array_slice($names, 0, 5)) . ").";
        }

        return new SslResult($expiresAt, is_string($issuer) ? $issuer : null, $note);
    }

    /** @return array<int,string> */
    private function subjectNames(array $parsed): array
    {
        $names = [];
        $common = $parsed['subject']['CN'] ?? null;

        if (is_string($common)) {
            $names[] = $common;
        }

        $alternative = $parsed['extensions']['subjectAltName'] ?? '';

        foreach (explode(',', (string) $alternative) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<int,string> $names */
    private function covers(string $domain, array $names): bool
    {
        $domain = strtolower($domain);

        foreach ($names as $name) {
            $name = strtolower($name);

            if ($name === $domain) {
                return true;
            }

            if (str_starts_with($name, '*.') && str_ends_with($domain, substr($name, 1))) {
                // Un joker ne couvre qu'un seul niveau de sous-domaine.
                $prefix = substr($domain, 0, -strlen($name) + 1);

                if ($prefix !== '' && !str_contains(rtrim($prefix, '.'), '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function explain(string $error): string
    {
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service') => "Le domaine ne se resout pas.",
            str_contains($lower, 'timed out') => "Aucune reponse TLS dans le delai imparti.",
            str_contains($lower, 'refused') => "Connexion refusee sur le port 443 : pas de HTTPS.",
            default => "Connexion TLS impossible : {$error}",
        };
    }
}
