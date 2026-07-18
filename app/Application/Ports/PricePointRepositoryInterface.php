<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Pricing\PriceSeries;
use DateTimeInterface;

/**
 * Port de persistance/cache pour les prix de marché (table `price_points`).
 * Utilisé par App\Infrastructure\PriceProvider\CachingPriceProvider pour
 * éviter de solliciter un provider externe (ENTSO-E...) à chaque simulation
 * portant sur le même horizon/zone.
 */
interface PricePointRepositoryInterface
{
    /**
     * Retourne la série en cache pour [from, to) si elle est complète
     * (une valeur par heure attendue), sinon null. Un cache partiel est
     * traité comme une absence de cache plutôt que fusionné avec un nouvel
     * appel provider : simplification V1 assumée (voir CachingPriceProvider).
     */
    public function findSeries(string $zone, DateTimeInterface $from, DateTimeInterface $to, string $source): ?PriceSeries;

    public function store(PriceSeries $series): void;
}
