<?php

declare(strict_types=1);

namespace App\Infrastructure\PriceProvider;

use App\Application\Ports\PricePointRepositoryInterface;
use App\Domain\Pricing\PriceProviderInterface;
use App\Domain\Pricing\PriceSeries;
use DateTimeInterface;

/**
 * Décore un PriceProviderInterface avec un cache persistant (table
 * `price_points`) : les prix day-ahead ne changent pas une fois publiés,
 * inutile de resolliciter un provider externe (rate-limité, latence
 * réseau) à chaque simulation portant sur le même horizon/zone.
 */
final class CachingPriceProvider implements PriceProviderInterface
{
    public function __construct(
        private readonly PriceProviderInterface $inner,
        private readonly PricePointRepositoryInterface $cache,
        private readonly string $source,
    ) {}

    public function getPrices(DateTimeInterface $from, DateTimeInterface $to, string $zone): PriceSeries
    {
        $cached = $this->cache->findSeries($zone, $from, $to, $this->source);

        if ($cached !== null) {
            return $cached;
        }

        $series = $this->inner->getPrices($from, $to, $zone);
        $this->cache->store($series);

        return $series;
    }
}
