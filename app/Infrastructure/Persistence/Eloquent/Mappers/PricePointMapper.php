<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Mappers;

use App\Domain\Pricing\PricePoint;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Persistence\Eloquent\Models\PricePointModel;
use DateTimeZone;
use Illuminate\Support\Carbon;

final class PricePointMapper
{
    public static function toDomain(PricePointModel $model): PricePoint
    {
        return new PricePoint(
            timestamp: $model->timestamp,
            pricePerMwh: Money::of($model->price_eur_per_mwh, $model->currency),
            zone: $model->zone,
            source: $model->source,
            resolutionMinutes: $model->resolution_minutes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toModelAttributes(PricePoint $point): array
    {
        return [
            'zone' => $point->zone(),
            'source' => $point->source(),
            // SQLite (et le format de date par défaut d'Eloquent en général)
            // stocke une chaîne naïve sans offset : un DateTimeImmutable
            // construit dans un fuseau non-UTC perdrait silencieusement son
            // offset et serait relu comme un instant différent (interprété
            // en UTC). On normalise donc systématiquement en UTC à la
            // frontière de persistance.
            'timestamp' => $point->timestamp()->setTimezone(new DateTimeZone('UTC')),
            'resolution_minutes' => $point->resolutionMinutes(),
            'price_eur_per_mwh' => $point->pricePerMwh()->amount(),
            'currency' => $point->pricePerMwh()->currency(),
            'retrieved_at' => Carbon::now('UTC'),
        ];
    }
}
