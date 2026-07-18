<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Contract\ValueObject\SeasonalTariff;
use App\Domain\Contract\ValueObject\TimeSlot;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Tarif heures pleines / heures creuses (HP/HC), avec tarifs éventuellement
 * saisonniers. Les plages horaires creuses sont explicites (`$offPeakSlots`) ;
 * toute heure qui n'appartient à aucune plage creuse est considérée en heures
 * pleines — c'est le fonctionnement réel des contrats HP/HC français, où la
 * plage HC (~8h/jour) est l'exception contractuelle et HP la règle.
 */
final class PeakOffPeakPricingStrategy implements PricingStrategyInterface
{
    /**
     * @param  list<TimeSlot>  $offPeakSlots  Plages horaires en heures creuses.
     * @param  list<SeasonalTariff>  $seasonalTariffs  Doit couvrir les 12 mois de l'année.
     */
    public function __construct(
        private readonly array $offPeakSlots,
        private readonly array $seasonalTariffs,
    ) {
        if ($this->seasonalTariffs === []) {
            throw DomainException::because('PeakOffPeakPricingStrategy requires at least one SeasonalTariff.');
        }
    }

    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money
    {
        $slotType = $this->isOffPeak($hour) ? TimeSlotType::OffPeak : TimeSlotType::Peak;

        $season = $this->seasonFor($hour);
        $rate = $season->rateFor($slotType);

        if ($rate === null) {
            throw DomainException::because(
                "Season '{$season->label()}' has no rate for slot '{$slotType->value}'."
            );
        }

        return $rate->pricePerKwh();
    }

    private function isOffPeak(DateTimeImmutable $hour): bool
    {
        foreach ($this->offPeakSlots as $slot) {
            if ($slot->contains($hour)) {
                return true;
            }
        }

        return false;
    }

    private function seasonFor(DateTimeImmutable $hour): SeasonalTariff
    {
        foreach ($this->seasonalTariffs as $season) {
            if ($season->appliesTo($hour)) {
                return $season;
            }
        }

        throw DomainException::because(
            'No SeasonalTariff covers month '.$hour->format('n').'.'
        );
    }
}
