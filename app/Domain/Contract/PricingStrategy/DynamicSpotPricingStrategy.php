<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Shared\ValueObject\Percentage;
use DateTimeImmutable;

/**
 * Tarif indexé sur le marché spot (day-ahead) : prix de gros + marge
 * fournisseur (marge relative sur le prix de gros, ex. l'énergie achetée
 * à prix coûtant) + frais fixe au kWh (acheminement, taxes...). C'est la
 * seule stratégie qui dépend réellement de $marketPrices — les prix
 * négatifs du marché de gros sont répercutés tels quels (avant application
 * de la marge/du frais fixe), ce qui peut donner un prix final encore
 * négatif ou simplement plus bas, selon le contrat.
 */
final class DynamicSpotPricingStrategy implements PricingStrategyInterface
{
    public function __construct(
        private readonly Money $supplierFeePerKwh,
        private readonly Percentage $supplierMargin,
    ) {}

    public function priceForHour(DateTimeImmutable $hour, ?PriceSeries $marketPrices = null): Money
    {
        if ($marketPrices === null) {
            throw DomainException::because(
                'DynamicSpotPricingStrategy requires market prices to compute a rate.'
            );
        }

        $wholesalePrice = $marketPrices->priceAt($hour);

        return $wholesalePrice
            ->multiply(1 + $this->supplierMargin->toFraction())
            ->add($this->supplierFeePerKwh);
    }
}
