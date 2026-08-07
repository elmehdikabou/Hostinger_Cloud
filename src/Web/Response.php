<?php

declare(strict_types=1);

namespace HostingerSpace\Web;

final readonly class Response
{
    public function __construct(
        public string $body,
        public int $status = 200,
        /** @var array<string,string> */
        public array $headers = [],
    ) {
    }

    /**
     * Redirection interne.
     *
     * L'adresse n'est jamais construite a partir de la requete : une
     * redirection ouverte permettrait d'envoyer quelqu'un ailleurs depuis un
     * lien qui semble pointer vers l'inventaire.
     */
    public static function redirect(string $path): self
    {
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '', '/');

        return new self('', 302, ['Location' => $path]);
    }

    public function send(): void
    {
        http_response_code($this->status);

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        // L'interface est entierement autonome : aucune ressource externe,
        // aucun script en ligne. La politique le fige, ce qui neutralise
        // l'injection de contenu meme si un nom de base contenait du HTML.
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'");

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
