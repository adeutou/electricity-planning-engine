<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Tarif "base" : un seul prix au kWh, quelle que soit l'heure ou la saison.
 */
final class FixedPricingStrategy implements PricingStrategyInterface
{
    public function __construct(
        private readonly Money $pricePerKwh,
    ) {}

    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money
    {
        return $this->pricePerKwh;
    }
}
