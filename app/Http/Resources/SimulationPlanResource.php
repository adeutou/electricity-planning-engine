<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;
use App\Application\Arbitrage\DTO\SimulationResultData;
use App\Domain\Arbitrage\ArbitragePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formate un plan d'arbitrage pour l'API. Accepte indifféremment un
 * SimulationResultData (retour direct de SimulateArbitrageUseCase, juste
 * après un POST /api/simulate) ou un SimulationPlanRecord (relu depuis la
 * persistance pour GET /api/plans/{id}) : les deux exposent les mêmes
 * propriétés `id` et `plan`, seul SimulationPlanRecord porte en plus les
 * métadonnées d'horizon/provider, incluses seulement si présentes.
 *
 * @mixin SimulationResultData
 */
final class SimulationPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ArbitragePlan $plan */
        $plan = $this->plan;

        return [
            'id' => $this->id,
            'zone' => $plan->zone(),
            'mode' => $plan->mode(),
            'totals' => [
                'cost_eur' => round($plan->totalCost()->amount(), 4),
                'consumption_kwh' => round($plan->totalConsumption()->kwh(), 4),
                'pv_production_kwh' => round($plan->totalPvProduction()->kwh(), 4),
                'export_kwh' => round($plan->totalExport()->kwh(), 4),
            ],
            'hours' => PlanHourResource::collection($plan->hours()),
            $this->mergeWhen($this->resource instanceof SimulationPlanRecord, function () {
                /** @var SimulationPlanRecord $record */
                $record = $this->resource;

                return [
                    'price_provider' => $record->priceProvider,
                    'horizon_start' => $record->horizonStart->format(DATE_ATOM),
                    'horizon_end' => $record->horizonEnd->format(DATE_ATOM),
                    'timezone' => $record->timezone,
                ];
            }),
        ];
    }
}
