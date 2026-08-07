<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

interface Detector
{
    /**
     * Priorite d'examen : les detecteurs specifiques passent avant les
     * generiques, sinon un WordPress serait identifie comme « PHP » parce
     * qu'il contient aussi des appels mysqli.
     */
    public function priority(): int;

    /** Retourne null si ce dossier ne correspond pas a cette application. */
    public function detect(SiteContext $context): ?Detection;
}
