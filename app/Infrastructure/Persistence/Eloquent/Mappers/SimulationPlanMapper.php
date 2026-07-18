<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Mappers;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;
use App\Domain\Arbitrage\ArbitragePlan;
use App\Domain\Arbitrage\HourlyDecision;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Persistence\Eloquent\Models\PlanHourModel;
use App\Infrastructure\Persistence\Eloquent\Models\SimulationPlanModel;
use DateTimeZone;

/**
 * Traduit entre (SimulationPlanModel + ses PlanHourModel) et
 * (SimulationPlanRecord enveloppant un ArbitragePlan). Les prix sont
 * stockés en €/MWh en base (cohérent avec `price_points`) mais manipulés en
 * €/kWh côté domaine (cf. HourlyDecision::pricePerKwh) : la conversion ×1000
 * / ÷1000 est centralisée ici plutôt que dupliquée dans les deux sens.
 *
 * Tous les DateTimeImmutable sont normalisés en UTC avant d'être remis à
 * Eloquent : le format de stockage par défaut ne conserve pas l'offset, un
 * horodatage construit dans le fuseau du contrat (ex. Europe/Paris) serait
 * sinon relu comme un instant différent (cf. le même correctif sur
 * PricePointMapper).
 */
final class SimulationPlanMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toModelAttributes(SimulationPlanRecord $record): array
    {
        $plan = $record->plan;

        return [
            'energy_contract_id' => $record->energyContractId,
            'mode' => $plan->mode(),
            'zone' => $plan->zone(),
            'price_provider' => $record->priceProvider,
            'horizon_start' => $record->horizonStart->setTimezone(new DateTimeZone('UTC')),
            'horizon_end' => $record->horizonEnd->setTimezone(new DateTimeZone('UTC')),
            'timezone' => $record->timezone,
            'contract_snapshot' => $record->contractSnapshot,
            'battery_config' => $record->batteryConfig,
            'pv_config' => $record->pvConfig,
            'consumption_config' => $record->consumptionConfig,
            'total_cost_eur' => $plan->totalCost()->amount(),
            'total_consumption_kwh' => $plan->totalConsumption()->kwh(),
            'total_pv_production_kwh' => $plan->totalPvProduction()->kwh(),
            'total_export_kwh' => $plan->totalExport()->kwh(),
            'metadata' => $record->metadata,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toHourModelAttributesList(ArbitragePlan $plan): array
    {
        return array_map(
            static fn (HourlyDecision $hour) => [
                'hour_index' => $hour->hourIndex(),
                'starts_at' => $hour->startsAt()->setTimezone(new DateTimeZone('UTC')),
                'price_eur_per_mwh' => $hour->pricePerKwh()->amount() * 1000,
                'consumption_kwh' => $hour->consumption()->kwh(),
                'pv_production_kwh' => $hour->pvProduction()->kwh(),
                'consumption_from_grid_kwh' => $hour->consumptionFromGrid()->kwh(),
                'consumption_from_pv_kwh' => $hour->consumptionFromPv()->kwh(),
                'consumption_from_battery_kwh' => $hour->consumptionFromBattery()->kwh(),
                'battery_charge_kwh' => $hour->batteryCharge()->kwh(),
                'battery_discharge_kwh' => $hour->batteryDischarge()->kwh(),
                'export_to_grid_kwh' => $hour->exportToGrid()->kwh(),
                'soc_end_of_hour_kwh' => $hour->socEndOfHour()->kwh(),
                'cost_eur' => $hour->cost()->amount(),
            ],
            $plan->hours()
        );
    }

    /**
     * @param  SimulationPlanModel  $model  Doit avoir la relation `hours` chargée.
     */
    public static function toDomainRecord(SimulationPlanModel $model): SimulationPlanRecord
    {
        $hours = $model->hours
            ->map(static fn (PlanHourModel $hourModel) => new HourlyDecision(
                hourIndex: $hourModel->hour_index,
                startsAt: $hourModel->starts_at,
                pricePerKwh: Money::of($hourModel->price_eur_per_mwh / 1000),
                consumption: Energy::fromKwh($hourModel->consumption_kwh),
                pvProduction: Energy::fromKwh($hourModel->pv_production_kwh),
                consumptionFromGrid: Energy::fromKwh($hourModel->consumption_from_grid_kwh),
                consumptionFromPv: Energy::fromKwh($hourModel->consumption_from_pv_kwh),
                consumptionFromBattery: Energy::fromKwh($hourModel->consumption_from_battery_kwh),
                batteryCharge: Energy::fromKwh($hourModel->battery_charge_kwh),
                batteryDischarge: Energy::fromKwh($hourModel->battery_discharge_kwh),
                exportToGrid: Energy::fromKwh($hourModel->export_to_grid_kwh),
                socEndOfHour: Energy::fromKwh($hourModel->soc_end_of_hour_kwh),
                cost: Money::of($hourModel->cost_eur),
            ))
            ->all();

        $plan = new ArbitragePlan($model->zone, $model->mode, $hours);

        return new SimulationPlanRecord(
            id: $model->id,
            plan: $plan,
            energyContractId: $model->energy_contract_id,
            priceProvider: $model->price_provider,
            horizonStart: $model->horizon_start,
            horizonEnd: $model->horizon_end,
            timezone: $model->timezone,
            contractSnapshot: $model->contract_snapshot ?? [],
            batteryConfig: $model->battery_config ?? [],
            pvConfig: $model->pv_config ?? [],
            consumptionConfig: $model->consumption_config ?? [],
            metadata: $model->metadata ?? [],
            createdAt: $model->created_at?->toImmutable(),
        );
    }
}
