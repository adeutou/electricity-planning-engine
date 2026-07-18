<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\PricePoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PricePoint
 */
final class PricePointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PricePoint $point */
        $point = $this->resource;

        return [
            'zone' => $point->zone(),
            'source' => $point->source(),
            'timestamp' => $point->timestamp()->format(DATE_ATOM),
            'resolution_minutes' => $point->resolutionMinutes(),
            'price_eur_per_mwh' => round($point->pricePerMwh()->amount(), 3),
            'price_eur_per_kwh' => round($point->pricePerKwh()->amount(), 6),
        ];
    }
}
