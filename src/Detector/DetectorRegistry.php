<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

use HostingerSpace\Detector\App\AdminTool;
use HostingerSpace\Detector\App\Drupal;
use HostingerSpace\Detector\App\GenericPhp;
use HostingerSpace\Detector\App\Dolibarr;
use HostingerSpace\Detector\App\Joomla;
use HostingerSpace\Detector\App\Laravel;
use HostingerSpace\Detector\App\Magento;
use HostingerSpace\Detector\App\PrestaShop;
use HostingerSpace\Detector\App\StaticSite;
use HostingerSpace\Detector\App\Symfony;
use HostingerSpace\Detector\App\WordPress;

/**
 * Applique les detecteurs dans l'ordre, du plus specifique au plus general.
 *
 * L'ordre compte : un WordPress contient des appels mysqli et un
 * index.html, il serait sinon classe « PHP sur mesure » ou « statique ».
 */
final class DetectorRegistry
{
    /** @var array<int,Detector> */
    private array $detectors;

    /** @param array<int,Detector>|null $detectors */
    public function __construct(?array $detectors = null)
    {
        $this->detectors = $detectors ?? self::defaults();

        usort(
            $this->detectors,
            static fn (Detector $a, Detector $b): int => $a->priority() <=> $b->priority()
        );
    }

    /** @return array<int,Detector> */
    public static function defaults(): array
    {
        return [
            new AdminTool(),
            new WordPress(),
            new Laravel(),
            new Symfony(),
            new Joomla(),
            new Dolibarr(),
            new PrestaShop(),
            new Drupal(),
            new Magento(),
            new GenericPhp(),
            new StaticSite(),
        ];
    }

    /**
     * Identifie un dossier. Retourne toujours une detection : un dossier
     * inconnu reste un dossier a inventorier.
     */
    public function detect(SiteContext $context): Detection
    {
        foreach ($this->detectors as $detector) {
            $detection = $detector->detect($context);

            if ($detection !== null) {
                return $detection;
            }
        }

        return new Detection(app: 'unknown', label: 'Non identifie');
    }
}
