<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Eloquent pour `energy_contracts`. Reste volontairement "anémique"
 * (pas de logique métier) : la traduction vers/depuis l'entité du domaine
 * App\Domain\Contract\EnergyContract est la responsabilité de
 * App\Infrastructure\Persistence\Eloquent\Mappers\EnergyContractMapper.
 *
 * @property int $id
 * @property string $name
 * @property string $country_code
 * @property string $zone
 * @property string $contract_type
 * @property string $currency
 * @property array<string, mixed> $pricing_config
 * @property float|null $subscribed_power_kva
 * @property string $timezone
 * @property bool $is_active
 */
final class EnergyContractModel extends Model
{
    use HasFactory;

    protected $table = 'energy_contracts';

    protected $fillable = [
        'name',
        'country_code',
        'zone',
        'contract_type',
        'currency',
        'pricing_config',
        'subscribed_power_kva',
        'timezone',
        'is_active',
    ];

    protected $casts = [
        'pricing_config' => 'array',
        'subscribed_power_kva' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<SimulationPlanModel, $this>
     */
    public function simulationPlans(): HasMany
    {
        return $this->hasMany(SimulationPlanModel::class, 'energy_contract_id');
    }
}
