<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Prix de marché pour une heure (ou un pas de temps) et une zone donnée.
 * Le prix brut est exprimé en €/MWh (convention des marchés day-ahead type
 * EPEX/ENTSO-E), volontairement conservé tel quel pour rester traçable
 * jusqu'à la source ; le domaine expose `pricePerKwh()` pour les calculs de
 * coût, qui raisonnent en kWh.
 */
final class PricePoint
{
    private readonly DateTimeImmutable $timestamp;

    private readonly Money $pricePerMwh;

    private readonly string $zone;

    private readonly string $source;

    private readonly int $resolutionMinutes;

    public function __construct(
        DateTimeImmutable $timestamp,
        Money $pricePerMwh,
        string $zone,
        string $source,
        int $resolutionMinutes = 60,
    ) {
        $this->timestamp = $timestamp;
        $this->pricePerMwh = $pricePerMwh;
        $this->zone = $zone;
        $this->source = $source;
        $this->resolutionMinutes = $resolutionMinutes;
    }

    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function pricePerMwh(): Money
    {
        return $this->pricePerMwh;
    }

    public function pricePerKwh(): Money
    {
        return $this->pricePerMwh->multiply(1 / 1000);
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function resolutionMinutes(): int
    {
        return $this->resolutionMinutes;
    }
}
