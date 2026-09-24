<?php

declare(strict_types=1);

namespace HostingerSpace\Backup;

/**
 * Une sauvegarde trouvee sur le disque : archive, dump SQL ou dossier d'un
 * greffon de sauvegarde.
 */
final class BackupArtifact
{
    public function __construct(
        /** Chemin relatif a la racine du compte. */
        public readonly string $path,
        public readonly int $sizeBytes,
        public readonly ?int $modifiedAt,
        /** 'dump', 'archive' ou 'dossier'. */
        public readonly string $kind,
        /** Cle du site dans lequel elle a ete trouvee, ou null a la racine. */
        public readonly ?string $siteKey = null,
        /**
         * Vrai quand le fichier se trouve sous une racine web : il est alors
         * telechargeable par quiconque devine son nom. Un dump SQL expose
         * ainsi la totalite d'une base — identifiants clients compris — sans
         * qu'aucune faille soit necessaire.
         */
        public readonly bool $webReachable = false,
    ) {
    }

    public function name(): string
    {
        return basename($this->path);
    }

    /** Une sauvegarde trop vieille ne protege plus de grand-chose. */
    public function ageInDays(): ?int
    {
        if ($this->modifiedAt === null) {
            return null;
        }

        return (int) floor((time() - $this->modifiedAt) / 86_400);
    }

    public function isStale(int $afterDays = 30): bool
    {
        $age = $this->ageInDays();

        return $age !== null && $age > $afterDays;
    }

    /**
     * Une archive vide ou minuscule n'est pas une sauvegarde : c'est un dump
     * interrompu, ou le fichier d'erreur d'un script de sauvegarde. La compter
     * comme une protection est pire que de n'en avoir aucune, puisqu'on cesse
     * alors de s'inquieter.
     */
    public function looksTruncated(): bool
    {
        return $this->kind !== 'dossier' && $this->sizeBytes < 1024;
    }
}
