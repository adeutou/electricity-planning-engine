<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Contract\Enum\SpecialDayColor;
use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Contract\ValueObject\SpecialDayRule;
use App\Domain\Contract\ValueObject\TariffRate;
use App\Domain\Contract\ValueObject\TimeSlot;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Tarif type EDF Tempo/EJP : chaque jour calendaire est classé Bleu/Blanc/
 * Rouge (cf. SpecialDayColor), avec un tarif HP/HC propre à chaque couleur
 * (les jours rouges sont les plus chers et jouent le rôle de "jours
 * d'effacement" mentionnés dans la fiche de poste). Le calendrier est fourni
 * explicitement plutôt que calculé (le vrai calendrier Tempo est publié par
 * EDF et n'obéit à aucune règle déterministe simple) ; tout jour absent du
 * calendrier retombe sur $defaultColor (Bleu dans l'immense majorité des cas
 * réels, ~300 jours/an).
 */
final class TempoPricingStrategy implements PricingStrategyInterface
{
    /**
     * @param  list<SpecialDayRule>  $calendar
     * @param  list<TimeSlot>  $offPeakSlots
     * @param  array<string, list<TariffRate>>  $ratesByColor  Clé = SpecialDayColor::value
     */
    public function __construct(
        private readonly array $calendar,
        private readonly array $offPeakSlots,
        private readonly array $ratesByColor,
        private readonly SpecialDayColor $defaultColor = SpecialDayColor::Blue,
    ) {
        foreach (SpecialDayColor::cases() as $color) {
            if (! isset($this->ratesByColor[$color->value])) {
                throw DomainException::because(
                    "TempoPricingStrategy is missing rates for color '{$color->value}'."
                );
            }
        }
    }

    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money
    {
        $color = $this->colorFor($hour);
        $slotType = $this->isOffPeak($hour) ? TimeSlotType::OffPeak : TimeSlotType::Peak;

        foreach ($this->ratesByColor[$color->value] as $rate) {
            if ($rate->slotType() === $slotType) {
                return $rate->pricePerKwh();
            }
        }

        throw DomainException::because(
            "No rate for color '{$color->value}' and slot '{$slotType->value}'."
        );
    }

    private function colorFor(DateTimeImmutable $hour): SpecialDayColor
    {
        foreach ($this->calendar as $rule) {
            if ($rule->matches($hour)) {
                return $rule->color();
            }
        }

        return $this->defaultColor;
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
}
