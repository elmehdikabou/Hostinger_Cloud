<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

enum FindingKind: string
{
    case OrphanDatabase = 'orphan_database';
    case MissingDatabase = 'missing_database';
    case SharedDatabase = 'shared_database';
    case EmptyDatabase = 'empty_database';
    case AbandonedSite = 'abandoned_site';
    case SiteWithoutDatabase = 'site_without_database';
    case UnreadableConfig = 'unreadable_config';
    case ExposedAdminTool = 'exposed_admin_tool';
    case DebugEnabled = 'debug_enabled';
    case OutdatedApp = 'outdated_app';
    case EmptyDirectory = 'empty_directory';
    case ExternalDatabase = 'external_database';
    case HttpError = 'http_error';
    case SslExpiring = 'ssl_expiring';
    case SslProblem = 'ssl_problem';
    case DomainExpiring = 'domain_expiring';
    case PartialCoverage = 'partial_coverage';

    public function label(): string
    {
        return match ($this) {
            self::OrphanDatabase => 'Base orpheline',
            self::MissingDatabase => 'Base manquante',
            self::SharedDatabase => 'Base partagee',
            self::EmptyDatabase => 'Base vide',
            self::AbandonedSite => 'Site sans activite',
            self::SiteWithoutDatabase => 'Site sans base rattachee',
            self::UnreadableConfig => 'Configuration illisible',
            self::ExposedAdminTool => 'Outil d\'administration expose',
            self::DebugEnabled => 'Mode debogage actif',
            self::OutdatedApp => 'Version obsolete',
            self::EmptyDirectory => 'Dossier vide',
            self::ExternalDatabase => 'Base hors Hostinger',
            self::HttpError => 'Site en erreur',
            self::SslExpiring => 'Certificat bientot expire',
            self::SslProblem => 'Probleme de certificat',
            self::DomainExpiring => 'Domaine bientot expire',
            self::PartialCoverage => 'Inventaire partiel',
        };
    }
}
