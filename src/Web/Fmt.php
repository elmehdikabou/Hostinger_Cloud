<?php

declare(strict_types=1);

namespace HostingerSpace\Web;

use HostingerSpace\Analysis\Linker;
use HostingerSpace\Console\Output;
use HostingerSpace\Model\FindingKind;

/**
 * Formatage pour les gabarits. Tout ce qui sort ici est destine a du HTML :
 * la fonction d'echappement est donc la premiere du fichier, et la plus
 * utilisee.
 */
final class Fmt
{
    private static int $scanId = 0;
    private static bool $scanIsLatest = true;

    /**
     * Fixe le releve consulte pour la duree de la requete.
     *
     * Les liens ne portent le numero de scan que si on regarde un releve
     * ancien : au quotidien les adresses restent courtes, et on ne se
     * retrouve pas fige sur un vieux scan en naviguant.
     */
    public static function useScan(int $scanId, bool $isLatest): void
    {
        self::$scanId = $scanId;
        self::$scanIsLatest = $isLatest;
    }

    /** Adresse interne, deja echappee pour un attribut HTML. */
    public static function url(string $target): string
    {
        if (self::$scanIsLatest || self::$scanId === 0) {
            return self::e($target);
        }

        return self::e($target . (str_contains($target, '?') ? '&' : '?') . 'scan=' . self::$scanId);
    }

    /** Adresse d'un site, identifie par sa cle. */
    public static function siteUrl(string $key): string
    {
        return self::url('/site?key=' . rawurlencode($key));
    }

    /** Adresse d'une base, identifiee par son nom. */
    public static function databaseUrl(string $name): string
    {
        return self::url('/database?name=' . rawurlencode($name));
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function bytes(int|string|null $bytes): string
    {
        return Linker::humanBytes((int) $bytes);
    }

    public static function date(int|string|null $timestamp): string
    {
        return $timestamp === null || $timestamp === '' ? '—' : date('d/m/Y', (int) $timestamp);
    }

    public static function dateTime(int|string|null $timestamp): string
    {
        return $timestamp === null || $timestamp === '' ? '—' : date('d/m/Y à H:i', (int) $timestamp);
    }

    public static function since(int|string|null $timestamp): string
    {
        return $timestamp === null || $timestamp === '' ? '—' : Output::since((int) $timestamp);
    }

    public static function number(int|string|null $value): string
    {
        return number_format((int) $value, 0, ',', ' ');
    }

    /**
     * Une base a-t-elle reellement ete ouverte et mesuree ?
     *
     * Les bases connues par la seule liste hPanel ne l'ont pas ete : elles
     * portent zero table et zero octet faute de mesure, pas parce qu'elles
     * sont vides. Les afficher comme vides serait une invitation a supprimer
     * une base pleine.
     *
     * @param array<string,mixed> $database Ligne issue de la table databases.
     */
    public static function measured(array $database): bool
    {
        // Les releves anterieurs a la colonne n'ont que des bases mesurees :
        // en leur absence, l'ancienne lecture reste la bonne.
        return (int) ($database['measured'] ?? 1) === 1;
    }

    /** Une mesure, ou « ? » quand la base n'a jamais pu etre ouverte. */
    public static function measuredValue(array $database, string $formatted): string
    {
        return self::measured($database) ? $formatted : '?';
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Critique',
            'warning' => 'À vérifier',
            default => 'Information',
        };
    }

    public static function kindLabel(string $kind): string
    {
        return FindingKind::tryFrom($kind)?->label() ?? $kind;
    }

    /** Classe CSS de l'etat d'un lien site/base. */
    public static function stateClass(string $state): string
    {
        return match ($state) {
            'linked' => 'ok',
            'missing' => 'critical',
            'external' => 'neutral',
            default => 'warning',
        };
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'linked' => 'Rattachée',
            'missing' => 'Introuvable',
            'external' => 'Externe',
            default => 'Non vérifiable',
        };
    }

    /** Etat lisible d'une reponse HTTP. */
    public static function httpClass(int|string|null $status): string
    {
        $status = (int) $status;

        return match (true) {
            $status === 0 => 'neutral',
            $status >= 500 => 'critical',
            $status >= 400 => 'warning',
            default => 'ok',
        };
    }

    /**
     * Nombre de jours avant une echeance, avec la classe de gravite
     * correspondante.
     *
     * @return array{0:string,1:string} [libelle, classe]
     */
    public static function expiry(int|string|null $timestamp, int $warningDays, int $criticalDays): array
    {
        if ($timestamp === null || $timestamp === '') {
            return ['—', 'neutral'];
        }

        $days = (int) floor(((int) $timestamp - time()) / 86_400);

        if ($days < 0) {
            return ['expiré depuis ' . abs($days) . ' j', 'critical'];
        }

        return [
            "dans {$days} j",
            match (true) {
                $days < $criticalDays => 'critical',
                $days < $warningDays => 'warning',
                default => 'ok',
            },
        ];
    }

    /**
     * Classe de largeur pour une barre de proportion, arrondie a 5 %.
     *
     * Les largeurs passent par des classes CSS et non par un attribut style :
     * la politique de securite de la page interdit le style en ligne, ce qui
     * ferme la porte a toute injection depuis un nom de base ou de domaine.
     */
    public static function barClass(float $ratio): string
    {
        $percent = (int) round(max(0.0, min(1.0, $ratio)) * 20) * 5;

        // Une valeur non nulle mais minuscule doit rester visible.
        if ($percent === 0 && $ratio > 0) {
            $percent = 5;
        }

        return 'w' . $percent;
    }

    public static function appIcon(string $app): string
    {
        return match ($app) {
            'wordpress' => 'W',
            'laravel' => 'L',
            'symfony' => 'S',
            'joomla' => 'J',
            'prestashop' => 'P',
            'drupal' => 'D',
            'magento' => 'M',
            'phpmyadmin', 'adminer' => '!',
            'static' => '≡',
            'placeholder' => '…',
            'empty' => '∅',
            default => '?',
        };
    }
}
