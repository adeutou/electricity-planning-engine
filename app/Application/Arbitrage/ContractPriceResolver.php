<?php

declare(strict_types=1);

namespace App\Application\Arbitrage;

use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Shared\ValueObject\Money;

/**
 * Résout le tarif contractuel (€/kWh) applicable à chaque heure de
 * l'horizon d'une simulation. Partagé par SimpleArbitrageEngine et
 * AdvancedArbitrageEngine : les deux moteurs raisonnent sur ce que le
 * foyer paie réellement (via EnergyContract::priceForHour, qui délègue à
 * la PricingStrategyInterface du contrat), jamais directement sur le prix
 * de marché brut.
 */
final class ContractPriceResolver
{
    /**
     * @return array<int, Money>
     */
    public static function resolve(ArbitrageContext $context): array
    {
        $prices = [];

        foreach ($context->horizon()->iterateHours() as $hourIndex => $hourStart) {
            $prices[$hourIndex] = $context->contract()->priceForHour($hourStart, $context->prices());
        }

        return $prices;
    }
}
