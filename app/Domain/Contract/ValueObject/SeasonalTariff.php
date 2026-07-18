<?php

declare(strict_types=1);

namespace App\Domain\Contract\ValueObject;

use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Shared\Exception\DomainException;
use DateTimeImmutable;

/**
 * Jeu de tarifs valable pour un sous-ensemble de mois de l'année (récurrent
 * chaque année), ex. "hiver" = [11,12,1,2,3] avec des tarifs HP/HC plus
 * élevés que l'été. Une PeakOffPeakPricingStrategy est composée d'une liste
 * de SeasonalTariff couvrant idéalement les 12 mois sans trou ni recouvrement
 * (non vérifié ici : c'est la responsabilité de la stratégie appelante, qui
 * peut lever si aucune saison ne correspond à une heure donnée).
 */
final class SeasonalTariff
{
    /** @var list<int> */
    private readonly array $months;

    /** @var list<TariffRate> */
    private readonly array $rates;

    private readonly string $label;

    /**
     * @param  list<int>  $months  Mois 1-12 couverts par cette saison.
     * @param  list<TariffRate>  $rates
     */
    public function __construct(string $label, array $months, array $rates)
    {
        foreach ($months as $month) {
            if ($month < 1 || $month > 12) {
                throw DomainException::because("Invalid month '{$month}', expected 1-12.");
            }
        }

        if ($rates === []) {
            throw DomainException::because("SeasonalTariff '{$label}' must declare at least one rate.");
        }

        $this->label = $label;
        $this->months = array_values(array_unique($months));
        $this->rates = array_values($rates);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function appliesTo(DateTimeImmutable $moment): bool
    {
        return in_array((int) $moment->format('n'), $this->months, true);
    }

    public function rateFor(TimeSlotType $slotType): ?TariffRate
    {
        foreach ($this->rates as $rate) {
            if ($rate->slotType() === $slotType) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * @return list<TariffRate>
     */
    public function rates(): array
    {
        return $this->rates;
    }
}
