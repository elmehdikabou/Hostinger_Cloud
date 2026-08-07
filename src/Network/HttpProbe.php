<?php

declare(strict_types=1);

namespace HostingerSpace\Network;

/**
 * Interroge un domaine en HTTP depuis la machine qui lance le scan.
 *
 * Cette verification dit ce que voit reellement un visiteur, ce que les
 * fichiers sur le disque ne disent pas : un dossier bien rempli peut
 * repondre 500 parce que sa base a disparu, et un domaine peut pointer
 * ailleurs depuis longtemps.
 */
final class HttpProbe
{
    public function __construct(private readonly int $timeout = 8)
    {
    }

    public function check(string $domain): HttpResult
    {
        if (!extension_loaded('curl')) {
            return new HttpResult(null, null, "Extension curl absente : verification HTTP impossible.");
        }

        $url = 'https://' . $domain . '/';
        $handle = curl_init($url);

        if ($handle === false) {
            return new HttpResult(null, null, 'Initialisation de la requete impossible.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'hspace/1.0 (inventaire d\'hebergement)',
            // On ne lit que le debut de la page : de quoi reconnaitre une page
            // de parcage ou une erreur, sans telecharger un site entier.
            CURLOPT_RANGE => '0-16383',
            CURLOPT_ACCEPT_ENCODING => '',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false && $status === 0) {
            return new HttpResult(null, null, $this->explain($error));
        }

        return new HttpResult(
            status: $status,
            finalUrl: $finalUrl !== '' ? $finalUrl : $url,
            note: $this->interpret($status, is_string($body) ? $body : ''),
        );
    }

    private function interpret(int $status, string $body): ?string
    {
        if ($status >= 500) {
            return "Le serveur repond {$status} : le site est en erreur (souvent une base injoignable ou une version de PHP incompatible).";
        }

        if ($status === 404) {
            return "Le serveur repond 404 : la racine du site ne sert aucune page.";
        }

        if ($status === 403) {
            return "Le serveur repond 403 : acces interdit, souvent un dossier sans page d'accueil.";
        }

        if ($status >= 400) {
            return "Le serveur repond {$status}.";
        }

        $sample = mb_strtolower(mb_substr(strip_tags($body), 0, 2_000));

        foreach ([
            'en construction' => "La page affiche « en construction ».",
            'coming soon' => "La page affiche « coming soon ».",
            'bientot disponible' => "La page affiche « bientot disponible ».",
            'site en maintenance' => "Le site est en maintenance.",
            'briefly unavailable for scheduled maintenance' => "WordPress est bloque en mode maintenance (fichier .maintenance a supprimer).",
            'error establishing a database connection' => "WordPress n'arrive pas a joindre sa base de donnees.",
            'index of /' => "Le serveur liste le contenu du dossier : aucune page d'accueil, et les fichiers sont exposes.",
            'default web site page' => "Page par defaut du serveur : rien n'est publie.",
        ] as $needle => $explanation) {
            if (str_contains($sample, $needle)) {
                return $explanation;
            }
        }

        return null;
    }

    private function explain(string $error): string
    {
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, 'could not resolve host') => "Le domaine ne se resout pas : aucune zone DNS, ou domaine expire.",
            str_contains($lower, 'timed out') => "Aucune reponse dans le delai imparti.",
            str_contains($lower, 'connection refused') => "Connexion refusee sur le port 443.",
            str_contains($lower, 'certificate') => "Probleme de certificat TLS : {$error}",
            $error === '' => "Aucune reponse HTTP.",
            default => $error,
        };
    }
}
