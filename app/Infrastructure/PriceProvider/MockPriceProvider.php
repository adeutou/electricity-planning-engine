<?php

declare(strict_types=1);

namespace App\Infrastructure\PriceProvider;

use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceProviderInterface;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Génère des prix spot réalistes sans dépendre d'un service externe : forme
 * "duck curve" journalière (creux, voire prix négatifs, en milieu de
 * journée quand la production solaire européenne est forte ; pic en soirée
 * quand la demande remonte sans le soleil) + bruit aléatoire. Sert de
 * provider par défaut (ENERGY_PRICE_PROVIDER=mock) pour développer et
 * démontrer le projet sans compte ENTSO-E.
 */
final class MockPriceProvider implements PriceProviderInterface
{
    private const SOURCE = 'mock';

    /**
     * Prix de base en €/MWh par heure de la journée (0-23). Volontairement
     * négatif en milieu de journée (11h-14h) pour exercer le cas "prix
     * négatifs" du cahier des charges dès la configuration par défaut.
     */
    private const BASE_CURVE_EUR_PER_MWH = [
        0 => 70, 1 => 65, 2 => 60, 3 => 58, 4 => 60, 5 => 65,
        6 => 80, 7 => 95, 8 => 90, 9 => 60, 10 => 30, 11 => 5,
        12 => -5, 13 => -10, 14 => -5, 15 => 10, 16 => 40, 17 => 90,
        18 => 140, 19 => 170, 20 => 150, 21 => 110, 22 => 90, 23 => 78,
    ];

    private const NOISE_AMPLITUDE_EUR_PER_MWH = 15.0;

    /**
     * @param  int|null  $seed  Graine pour un bruit reproductible (tests, démos) ; aléatoire cryptographique si null.
     */
    public function __construct(
        private readonly ?int $seed = null,
    ) {}

    public function getPrices(DateTimeInterface $from, DateTimeInterface $to, string $zone): PriceSeries
    {
        $randomizer = new Randomizer($this->seed !== null ? new Mt19937($this->seed) : new Secure());

        $cursor = DateTimeImmutable::createFromInterface($from);
        $end = DateTimeImmutable::createFromInterface($to);

        $points = [];

        while ($cursor < $end) {
            $hourOfDay = (int) $cursor->format('G');
            $base = self::BASE_CURVE_EUR_PER_MWH[$hourOfDay];

            // Bruit uniforme +/- NOISE_AMPLITUDE, résolution 0.01 €/MWh.
            $noiseSteps = (int) (self::NOISE_AMPLITUDE_EUR_PER_MWH * 100);
            $noise = $randomizer->getInt(-$noiseSteps, $noiseSteps) / 100;

            $points[] = new PricePoint(
                timestamp: $cursor,
                pricePerMwh: Money::of($base + $noise),
                zone: $zone,
                source: self::SOURCE,
            );

            $cursor = $cursor->modify('+1 hour');
        }

        return new PriceSeries($zone, $points);
    }
}
