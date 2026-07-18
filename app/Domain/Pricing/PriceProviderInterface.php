<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeInterface;

/**
 * Port du domaine vers une source de prix de marché (mock, ENTSO-E, EPEX...).
 * L'Infrastructure fournit les implémentations concrètes ; le domaine et
 * l'Application ne dépendent que de cette abstraction (cf.
 * App\Infrastructure\PriceProvider\PriceProviderFactory pour la résolution
 * de l'implémentation active via config('energy.price_provider')).
 */
interface PriceProviderInterface
{
    /**
     * @param  DateTimeInterface  $from  Début de l'horizon (inclus).
     * @param  DateTimeInterface  $to  Fin de l'horizon (exclu).
     * @param  string  $zone  Zone de marché (ex. "FR", "DE_LU", "BE").
     */
    public function getPrices(DateTimeInterface $from, DateTimeInterface $to, string $zone): PriceSeries;
}
