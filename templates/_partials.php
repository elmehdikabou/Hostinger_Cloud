<?php

use HostingerSpace\Storage\ScanRepository;
use HostingerSpace\Web\Fmt;

/**
 * Fragments reutilises par plusieurs pages.
 * Inclus une seule fois grace a require_once dans les gabarits appelants.
 */

if (!function_exists('hs_page_head')) {
    function hs_page_head(string $title, string $subtitle = ''): string
    {
        return '<div class="page-head"><div><h1>' . Fmt::e($title) . '</h1>'
            . ($subtitle !== '' ? '<p class="subtitle">' . Fmt::e($subtitle) . '</p>' : '')
            . '</div></div>';
    }
}

if (!function_exists('hs_app_tag')) {
    /** Nom de la technologie precede de sa pastille. */
    function hs_app_tag(string $app, string $label, ?string $version = null): string
    {
        return '<span class="app-tag"><span class="app-mark">' . Fmt::e(Fmt::appIcon($app)) . '</span>'
            . '<span>' . Fmt::e($label) . ($version !== null && $version !== '' ? ' ' . Fmt::e($version) : '') . '</span></span>';
    }
}

if (!function_exists('hs_site_name')) {
    function hs_site_name(array $site): string
    {
        return (string) $site['domain'] . ($site['mount_path'] !== '/' ? (string) $site['mount_path'] : '');
    }
}

if (!function_exists('hs_finding')) {
    /** Un constat, avec sa bande de gravite et ses actions. */
    function hs_finding(array $finding, bool $withActions = true): string
    {
        $severity = (string) $finding['severity'];

        $html = '<div class="finding ' . Fmt::e($severity) . '"><div class="stripe"></div><div class="body">'
            . '<div class="meta">'
            . '<span class="badge ' . Fmt::e($severity === 'info' ? 'neutral' : $severity) . '">'
            . Fmt::e(Fmt::severityLabel($severity)) . '</span>'
            . '<span class="muted">' . Fmt::e(Fmt::kindLabel((string) $finding['kind'])) . '</span>'
            . '</div>'
            . '<div class="title">' . Fmt::e((string) $finding['title']) . '</div>'
            . '<div class="detail">' . Fmt::e((string) $finding['detail']) . '</div>';

        $actions = $withActions ? ScanRepository::decode($finding['actions'] ?? null) : [];

        if ($actions !== []) {
            $html .= '<ul class="actions">';

            foreach ($actions as $action) {
                $action = (string) $action;

                // Une action qui contient une commande est presentee comme
                // telle : on doit pouvoir la copier sans la reconstituer.
                if (preg_match('/^(.*?:)\s*((?:mysqldump|grep|mysql|php)\s.+)$/s', $action, $matches) === 1) {
                    $html .= '<li>' . Fmt::e(trim($matches[1]))
                        . '<code class="command">' . Fmt::e(trim($matches[2])) . '</code></li>';
                } else {
                    $html .= '<li>' . Fmt::e($action) . '</li>';
                }
            }

            $html .= '</ul>';
        }

        return $html . '</div></div>';
    }
}

if (!function_exists('hs_bar')) {
    function hs_bar(int $value, int $max, bool $orphan = false): string
    {
        $ratio = $max > 0 ? $value / $max : 0.0;

        return '<div class="bar' . ($orphan ? ' is-orphan' : '') . '">'
            . '<span class="' . Fmt::e(Fmt::barClass($ratio)) . '"></span></div>';
    }
}
