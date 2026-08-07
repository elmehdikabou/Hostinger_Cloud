<?php

declare(strict_types=1);

namespace HostingerSpace\Web;

/**
 * Rendu des gabarits PHP, avec mise en page commune.
 */
final class View
{
    public function __construct(
        private readonly string $directory,
        /** @var array<string,mixed> Donnees disponibles dans tous les gabarits. */
        private readonly array $shared = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->capture($template, $data);

        return $this->capture('layout', array_merge($data, [
            'content' => $content,
            'pageTemplate' => $template,
        ]));
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->directory . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Gabarit introuvable : {$template}");
        }

        $view = $this;

        // extract() prend son tableau par reference : on lui passe des copies
        // locales, sinon PHP refuse de toucher a la propriete readonly.
        $shared = $this->shared;
        $local = $data;

        extract($shared, EXTR_SKIP);
        extract($local, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
