<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\HourlyProfile;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use DateTimeImmutable;

/**
 * Profil de consommation d'un foyer/PME. Si un profil horaire explicite est
 * fourni (mesure réelle ou prévision), il est utilisé tel quel ; sinon une
 * courbe synthétique de consommation résidentielle (deux pics matin/soir)
 * répartit une consommation journalière moyenne, pour permettre des
 * simulations sans données de terrain.
 */
final class ConsumptionProfile
{
    /**
     * Poids relatifs par heure de la journée (0-23), typiques d'un foyer
     * résidentiel : pic du matin (~7h-9h) et pic du soir (~18h-22h), creux
     * nocturne. Normalisés à l'usage (somme non garantie égale à 1) plutôt
     * que codés en dur à 1.000 pile, pour rester faciles à ajuster.
     */
    private const HOURLY_WEIGHTS = [
        0 => 0.020, 1 => 0.015, 2 => 0.015, 3 => 0.015, 4 => 0.015, 5 => 0.020,
        6 => 0.035, 7 => 0.055, 8 => 0.060, 9 => 0.045, 10 => 0.035, 11 => 0.035,
        12 => 0.045, 13 => 0.040, 14 => 0.035, 15 => 0.035, 16 => 0.040, 17 => 0.050,
        18 => 0.065, 19 => 0.075, 20 => 0.070, 21 => 0.060, 22 => 0.045, 23 => 0.030,
    ];

    public function __construct(
        private readonly ?HourlyProfile $profile = null,
        private readonly ?Energy $dailyBaseline = null,
    ) {
        if ($this->profile === null && $this->dailyBaseline === null) {
            throw DomainException::because(
                'ConsumptionProfile requires either an explicit hourly profile or a daily baseline to estimate from.'
            );
        }
    }

    public function hasExplicitProfile(): bool
    {
        return $this->profile !== null;
    }

    public function consumptionAt(DateTimeImmutable $hour, int $hourIndex): Energy
    {
        if ($this->profile !== null) {
            return $this->profile->at($hourIndex);
        }

        return $this->estimateFromBaseline($hour);
    }

    private function estimateFromBaseline(DateTimeImmutable $hour): Energy
    {
        $hourOfDay = (int) $hour->format('G');
        $weight = self::HOURLY_WEIGHTS[$hourOfDay] / array_sum(self::HOURLY_WEIGHTS);

        /** @var Energy $dailyBaseline */
        $dailyBaseline = $this->dailyBaseline;

        return $dailyBaseline->multiply($weight);
    }
}
