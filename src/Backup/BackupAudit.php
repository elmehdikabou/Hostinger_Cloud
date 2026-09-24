<?php

declare(strict_types=1);

namespace HostingerSpace\Backup;

use HostingerSpace\Model\Finding;
use HostingerSpace\Model\FindingKind;
use HostingerSpace\Model\Severity;
use HostingerSpace\Model\Site;

/**
 * Tire les constats de ce que le scanner a trouve.
 *
 * L'ordre de gravite est volontaire et ne suit pas l'intuition : une
 * sauvegarde telechargeable est plus grave qu'une sauvegarde absente. Sans
 * sauvegarde, on risque de perdre ses donnees ; avec une sauvegarde exposee,
 * on les a deja donnees, et on ne le sait pas.
 */
final class BackupAudit
{
    public function __construct(private readonly int $staleAfterDays = 30)
    {
    }

    /**
     * @param array<int,BackupArtifact> $artifacts
     * @param array<int,Site>           $sites
     *
     * @return array<int,Finding>
     */
    public function findings(array $artifacts, array $sites): array
    {
        $findings = [];

        /*
         * Un dossier de greffon expose contient dix archives, toutes exposees
         * pour la meme raison et corrigees par le meme geste. Les signaler une
         * par une noierait les autres constats sous une repetition, et ferait
         * croire a dix problemes la ou il y en a un.
         */
        $exposedDirectories = [];

        foreach ($artifacts as $artifact) {
            if ($artifact->kind === 'dossier' && $artifact->webReachable) {
                $exposedDirectories[] = $artifact->path;
            }
        }

        foreach ($artifacts as $artifact) {
            if ($artifact->webReachable && !$this->insideOneOf($artifact, $exposedDirectories)) {
                $findings[] = $this->exposed($artifact);
            }

            if ($artifact->looksTruncated()) {
                $findings[] = new Finding(
                    kind: FindingKind::TruncatedBackup,
                    severity: Severity::Warning,
                    title: "La sauvegarde « {$artifact->name()} » fait moins d'un kilo-octet",
                    detail: "Une archive de cette taille ne contient rien : dump interrompu, erreur de "
                        . "script, ou fichier cree puis jamais rempli. La compter comme une protection "
                        . "est pire que de n'en avoir aucune, puisqu'on cesse alors de s'inquieter.",
                    subjectType: 'backup',
                    subject: $artifact->path,
                    actions: [
                        'Ouvrir le fichier pour verifier ce qu\'il contient.',
                        'Relancer la sauvegarde, puis controler la taille obtenue.',
                    ],
                );
            }
        }

        foreach ($this->sitesWithoutBackup($artifacts, $sites) as $site) {
            $findings[] = new Finding(
                kind: FindingKind::NoBackup,
                severity: Severity::Warning,
                title: "{$site->domain}{$this->mount($site)} : aucune sauvegarde trouvee",
                detail: "Aucune archive ni dump n'a ete trouve dans ce site. Hostinger conserve ses "
                    . "propres sauvegardes automatiques dans hPanel, qui ne sont pas visibles ici : "
                    . "verifie leur date avant de conclure.",
                subjectType: 'site',
                subject: $site->key,
                actions: [
                    'hPanel > Fichiers > Sauvegardes : verifier la date de la derniere sauvegarde.',
                    'Pour la base : mysqldump -u UTILISATEUR -p NOM_BASE > ~/sauvegarde-NOM_BASE.sql',
                ],
            );
        }

        $stale = array_filter(
            $artifacts,
            fn (BackupArtifact $a): bool => $a->kind !== 'dossier' && $a->isStale($this->staleAfterDays)
        );

        if ($stale !== []) {
            $oldest = 0;

            foreach ($stale as $artifact) {
                $oldest = max($oldest, $artifact->ageInDays() ?? 0);
            }

            $findings[] = new Finding(
                kind: FindingKind::StaleBackup,
                severity: Severity::Info,
                title: count($stale) . " sauvegarde(s) datent de plus de {$this->staleAfterDays} jours",
                detail: "La plus ancienne remonte a {$oldest} jours. Une sauvegarde ne protege que "
                    . "jusqu'a sa date : tout ce qui a ete saisi depuis serait perdu.",
                subjectType: 'backup',
                subject: 'anciennete',
                actions: ['Verifier que des sauvegardes recentes existent ailleurs, puis faire le menage.'],
            );
        }

        return $findings;
    }

    /**
     * Ce fichier est-il deja couvert par un dossier signale ?
     *
     * @param array<int,string> $directories
     */
    private function insideOneOf(BackupArtifact $artifact, array $directories): bool
    {
        foreach ($directories as $directory) {
            if ($artifact->path !== $directory && str_starts_with($artifact->path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    private function exposed(BackupArtifact $artifact): Finding
    {
        $dump = $artifact->kind === 'dump';

        /*
         * Deplacer le dossier d'un greffon de sauvegarde le casserait : il y
         * ecrit a chaque execution. On le protege sur place, ce qui laisse le
         * greffon fonctionner tout en fermant l'acces.
         */
        if ($artifact->kind === 'dossier') {
            return new Finding(
                kind: FindingKind::ExposedBackup,
                severity: Severity::Critical,
                title: "Dossier de sauvegardes accessible depuis le web : {$artifact->name()}",
                detail: "Ce dossier se trouve sous une racine web. Les archives qu'il contient se "
                    . "telechargent en devinant leur nom, et une archive de site porte la base et "
                    . "les fichiers de configuration avec leurs identifiants.",
                subjectType: 'backup',
                subject: $artifact->path,
                actions: [
                    // Le deplacer casserait le greffon, qui y ecrit a chaque
                    // execution : on ferme l'acces sans deranger l'outil.
                    'Interdire l\'acces sans deplacer le dossier :',
                    'printf \'Require all denied\\n\' > ~/' . $artifact->path . '/.htaccess',
                    'Verifier ensuite qu\'une archive n\'est plus telechargeable depuis un navigateur.',
                ],
            );
        }

        return new Finding(
            // Un dump livre une base entiere ; une archive, le code et souvent
            // les fichiers de configuration avec leurs mots de passe. Les deux
            // sont critiques, mais le premier se lit sans rien installer.
            kind: FindingKind::ExposedBackup,
            severity: Severity::Critical,
            title: ($dump ? 'Dump SQL' : 'Sauvegarde') . " telechargeable : {$artifact->name()}",
            detail: "Ce fichier se trouve sous une racine web. Quiconque devine son nom peut le "
                . ($dump
                    ? "telecharger et lire la base entiere : comptes, adresses, mots de passe haches."
                    : "telecharger, et y trouver les fichiers de configuration avec leurs identifiants.")
                . " Aucune faille n'est necessaire, et rien n'en laisse de trace visible.",
            subjectType: 'backup',
            subject: $artifact->path,
            actions: [
                'Deplacer le fichier hors de la racine web : mv ' . $artifact->path . ' ~/sauvegardes/',
                'Ou le supprimer s\'il ne sert plus.',
                'Verifier ensuite qu\'il n\'est plus telechargeable depuis un navigateur.',
            ],
        );
    }

    /**
     * @param array<int,BackupArtifact> $artifacts
     * @param array<int,Site>           $sites
     *
     * @return array<int,Site>
     */
    private function sitesWithoutBackup(array $artifacts, array $sites): array
    {
        $covered = [];

        foreach ($artifacts as $artifact) {
            if ($artifact->siteKey !== null && !$artifact->looksTruncated()) {
                $covered[$artifact->siteKey] = true;
            }
        }

        return array_values(array_filter(
            $sites,
            static fn (Site $s): bool => !isset($covered[$s->key])
        ));
    }

    private function mount(Site $site): string
    {
        return $site->mountPath !== '/' ? $site->mountPath : '';
    }
}
