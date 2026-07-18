<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\HourlyProfile;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Power;
use DateTimeImmutable;

/**
 * Installation photovoltaïque. Si un profil horaire explicite est fourni
 * (mesure réelle ou prévision météo), il est utilisé tel quel ; sinon un
 * modèle synthétique en cloche approxime la production à partir de la seule
 * puissance crête, pour permettre des simulations "hors-sol" en démo.
 */
final class PhotovoltaicSystem
{
    private const SUNRISE_HOUR = 7.0;

    private const SUNSET_HOUR = 21.0;

    public function __construct(
        private readonly Power $peakPower,
        private readonly ?HourlyProfile $productionProfile = null,
    ) {}

    public function peakPower(): Power
    {
        return $this->peakPower;
    }

    public function hasExplicitProfile(): bool
    {
        return $this->productionProfile !== null;
    }

    public function productionAt(DateTimeImmutable $hour, int $hourIndex): Energy
    {
        if ($this->productionProfile !== null) {
            return $this->productionProfile->at($hourIndex);
        }

        return $this->estimateProduction($hour);
    }

    /**
     * Modèle synthétique simplifié : courbe en cloche (cosinus) entre lever
     * et coucher du soleil fixes (7h-21h, pic à 14h), sans dépendance à la
     * latitude, la saison ou la météo réelle. Simplification assumée pour
     * une valeur par défaut de démo — à remplacer par un vrai modèle
     * d'irradiance (ex. PVGIS, Copernicus/Meteomatics) pour un usage réel.
     */
    private function estimateProduction(DateTimeImmutable $hour): Energy
    {
        $hourOfDay = (float) $hour->format('H') + ((float) $hour->format('i') / 60);

        if ($hourOfDay <= self::SUNRISE_HOUR || $hourOfDay >= self::SUNSET_HOUR) {
            return Energy::zero();
        }

        $solarNoon = (self::SUNRISE_HOUR + self::SUNSET_HOUR) / 2;
        $halfDaylight = (self::SUNSET_HOUR - self::SUNRISE_HOUR) / 2;
        $normalizedOffset = ($hourOfDay - $solarNoon) / $halfDaylight; // -1..1
        $factor = max(0.0, cos($normalizedOffset * M_PI / 2));

        return $this->peakPower->toEnergy(1.0)->multiply($factor);
    }
}
