<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Domain\Pricing\PriceSeries;
use DateTimeImmutable;

/**
 * Cas d'usage pour GET /api/prices : expose les prix bruts d'une zone sur un
 * horizon donné, sans passer par un contrat ni un moteur d'arbitrage. Utile
 * pour inspecter/déboguer ce qu'un provider retourne avant de lancer une
 * simulation complète.
 */
final class FetchPriceSeriesUseCase
{
    public function __construct(
        private readonly PriceProviderResolver $priceProviders,
    ) {}

    public function handle(DateTimeImmutable $from, DateTimeImmutable $to, string $zone, ?string $providerOverride): PriceSeries
    {
        return $this->priceProviders->resolve($providerOverride)->getPrices($from, $to, $zone);
    }
}
