<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Eloquent pour `simulation_plans`. Clé primaire ULID (voir
 * migration) : HasUlids se charge de la générer à la création et de
 * configurer le modèle en conséquence (clé non incrémentale, de type string).
 *
 * @property string $id
 * @property int|null $energy_contract_id
 * @property string $mode
 * @property string $zone
 * @property string $price_provider
 * @property \Illuminate\Support\Carbon $horizon_start
 * @property \Illuminate\Support\Carbon $horizon_end
 * @property string $timezone
 * @property array<string, mixed>|null $contract_snapshot
 * @property array<string, mixed>|null $battery_config
 * @property array<string, mixed>|null $pv_config
 * @property array<string, mixed>|null $consumption_config
 * @property float $total_cost_eur
 * @property float $total_consumption_kwh
 * @property float $total_pv_production_kwh
 * @property float $total_export_kwh
 * @property array<string, mixed>|null $metadata
 */
final class SimulationPlanModel extends Model
{
    use HasUlids;

    protected $table = 'simulation_plans';

    protected $fillable = [
        'energy_contract_id',
        'mode',
        'zone',
        'price_provider',
        'horizon_start',
        'horizon_end',
        'timezone',
        'contract_snapshot',
        'battery_config',
        'pv_config',
        'consumption_config',
        'total_cost_eur',
        'total_consumption_kwh',
        'total_pv_production_kwh',
        'total_export_kwh',
        'metadata',
    ];

    protected $casts = [
        'horizon_start' => 'immutable_datetime',
        'horizon_end' => 'immutable_datetime',
        'contract_snapshot' => 'array',
        'battery_config' => 'array',
        'pv_config' => 'array',
        'consumption_config' => 'array',
        'total_cost_eur' => 'float',
        'total_consumption_kwh' => 'float',
        'total_pv_production_kwh' => 'float',
        'total_export_kwh' => 'float',
        'metadata' => 'array',
    ];

    /**
     * @return HasMany<PlanHourModel, $this>
     */
    public function hours(): HasMany
    {
        return $this->hasMany(PlanHourModel::class, 'simulation_plan_id')->orderBy('hour_index');
    }

    /**
     * @return BelongsTo<EnergyContractModel, $this>
     */
    public function energyContract(): BelongsTo
    {
        return $this->belongsTo(EnergyContractModel::class, 'energy_contract_id');
    }
}
