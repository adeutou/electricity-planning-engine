<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Arbitrage\HourlyDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HourlyDecision
 */
final class PlanHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HourlyDecision $hour */
        $hour = $this->resource;

        return [
            'hour_index' => $hour->hourIndex(),
            'starts_at' => $hour->startsAt()->format(DATE_ATOM),
            'price_eur_per_kwh' => round($hour->pricePerKwh()->amount(), 6),
            'consumption_kwh' => round($hour->consumption()->kwh(), 4),
            'pv_production_kwh' => round($hour->pvProduction()->kwh(), 4),
            'consumption_from_grid_kwh' => round($hour->consumptionFromGrid()->kwh(), 4),
            'consumption_from_pv_kwh' => round($hour->consumptionFromPv()->kwh(), 4),
            'consumption_from_battery_kwh' => round($hour->consumptionFromBattery()->kwh(), 4),
            'battery_charge_kwh' => round($hour->batteryCharge()->kwh(), 4),
            'battery_discharge_kwh' => round($hour->batteryDischarge()->kwh(), 4),
            'export_to_grid_kwh' => round($hour->exportToGrid()->kwh(), 4),
            'soc_end_of_hour_kwh' => round($hour->socEndOfHour()->kwh(), 4),
            'cost_eur' => round($hour->cost()->amount(), 6),
        ];
    }
}
