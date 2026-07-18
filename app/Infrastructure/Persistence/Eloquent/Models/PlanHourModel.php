<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Eloquent pour `plan_hours` (détail heure par heure d'un plan).
 * Pas de timestamps : ligne immuable créée une seule fois avec son plan
 * parent (voir migration correspondante).
 *
 * @property int $id
 * @property string $simulation_plan_id
 * @property int $hour_index
 * @property \Illuminate\Support\Carbon $starts_at
 * @property float $price_eur_per_mwh
 * @property float $consumption_kwh
 * @property float $pv_production_kwh
 * @property float $consumption_from_grid_kwh
 * @property float $consumption_from_pv_kwh
 * @property float $consumption_from_battery_kwh
 * @property float $battery_charge_kwh
 * @property float $battery_discharge_kwh
 * @property float $export_to_grid_kwh
 * @property float $soc_end_of_hour_kwh
 * @property float $cost_eur
 */
final class PlanHourModel extends Model
{
    public $timestamps = false;

    protected $table = 'plan_hours';

    protected $fillable = [
        'simulation_plan_id',
        'hour_index',
        'starts_at',
        'price_eur_per_mwh',
        'consumption_kwh',
        'pv_production_kwh',
        'consumption_from_grid_kwh',
        'consumption_from_pv_kwh',
        'consumption_from_battery_kwh',
        'battery_charge_kwh',
        'battery_discharge_kwh',
        'export_to_grid_kwh',
        'soc_end_of_hour_kwh',
        'cost_eur',
    ];

    protected $casts = [
        'hour_index' => 'integer',
        'starts_at' => 'immutable_datetime',
        'price_eur_per_mwh' => 'float',
        'consumption_kwh' => 'float',
        'pv_production_kwh' => 'float',
        'consumption_from_grid_kwh' => 'float',
        'consumption_from_pv_kwh' => 'float',
        'consumption_from_battery_kwh' => 'float',
        'battery_charge_kwh' => 'float',
        'battery_discharge_kwh' => 'float',
        'export_to_grid_kwh' => 'float',
        'soc_end_of_hour_kwh' => 'float',
        'cost_eur' => 'float',
    ];

    /**
     * @return BelongsTo<SimulationPlanModel, $this>
     */
    public function simulationPlan(): BelongsTo
    {
        return $this->belongsTo(SimulationPlanModel::class, 'simulation_plan_id');
    }
}
