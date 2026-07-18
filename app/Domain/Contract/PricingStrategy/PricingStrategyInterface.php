<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Calcule le tarif applicable (€/kWh) pour une heure donnée d'un contrat.
 * Pattern Strategy : chaque famille de contrat (fixe, HP/HC, Tempo, spot...)
 * a sa propre implémentation, ce qui permet d'ajouter un nouveau type de
 * contrat ou un nouveau pays sans toucher à EnergyContract ni au moteur
 * d'arbitrage.
 *
 * $marketPrices n'est utilisé que par les stratégies indexées sur le marché
 * (ex. DynamicSpotPricingStrategy) ; les autres l'ignorent.
 */
interface PricingStrategyInterface
{
    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money;
}
