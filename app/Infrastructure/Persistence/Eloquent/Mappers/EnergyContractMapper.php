<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Mappers;

use App\Domain\Contract\Enum\ContractType;
use App\Domain\Contract\EnergyContract;
use App\Domain\Contract\PricingStrategy\PricingStrategyFactory;
use App\Infrastructure\Persistence\Eloquent\Models\EnergyContractModel;

/**
 * Traduit entre le modèle Eloquent EnergyContractModel et l'entité du
 * domaine App\Domain\Contract\EnergyContract. C'est le seul endroit qui
 * connaît à la fois Eloquent et le Domain pour ce concept : ni l'un ni
 * l'autre n'a de dépendance directe vers l'autre.
 */
final class EnergyContractMapper
{
    public static function toDomain(EnergyContractModel $model): EnergyContract
    {
        $type = ContractType::from($model->contract_type);
        $strategy = PricingStrategyFactory::fromConfig($type, $model->pricing_config);

        return new EnergyContract(
            name: $model->name,
            countryCode: $model->country_code,
            zone: $model->zone,
            type: $type,
            pricingStrategy: $strategy,
            currency: $model->currency,
            subscribedPowerKva: $model->subscribed_power_kva,
            timezone: $model->timezone,
        );
    }

    /**
     * Instantané JSON du contrat tel que persisté, destiné à être copié dans
     * `simulation_plans.contract_snapshot` pour qu'un plan reste
     * reproductible même si le contrat source change ensuite (cf.
     * SimulationPlanRecord).
     *
     * @return array<string, mixed>
     */
    public static function toSnapshotArray(EnergyContractModel $model): array
    {
        return [
            'name' => $model->name,
            'country_code' => $model->country_code,
            'zone' => $model->zone,
            'contract_type' => $model->contract_type,
            'currency' => $model->currency,
            'pricing_config' => $model->pricing_config,
            'subscribed_power_kva' => $model->subscribed_power_kva,
            'timezone' => $model->timezone,
        ];
    }
}
