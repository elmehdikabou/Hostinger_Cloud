<?php

declare(strict_types=1);

namespace HostingerSpace\Model;

enum LinkState: string
{
    /** Le site declare une base, et cette base existe bien sur le serveur. */
    case Linked = 'linked';

    /** Le site declare une base introuvable cote MySQL : le site est probablement casse. */
    case Missing = 'missing';

    /** La base est hebergee ailleurs : rien a verifier ici. */
    case External = 'external';

    /**
     * La base declaree n'est visible par aucun de nos acces MySQL. Elle peut
     * exister sans qu'on la voie : on ne conclut pas.
     */
    case Unverifiable = 'unverifiable';

    public function label(): string
    {
        return match ($this) {
            self::Linked => 'Rattachee',
            self::Missing => 'Introuvable',
            self::External => 'Externe',
            self::Unverifiable => 'Non verifiable',
        };
    }
}
