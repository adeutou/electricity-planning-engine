<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Arbitrage\DTO\EnergyContractInput;
use App\Application\Arbitrage\DTO\EnergyContractRecord;
use App\Application\Ports\EnergyContractRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Mappers\EnergyContractMapper;
use App\Infrastructure\Persistence\Eloquent\Models\EnergyContractModel;

final class EloquentEnergyContractRepository implements EnergyContractRepositoryInterface
{
    public function create(EnergyContractInput $input): EnergyContractRecord
    {
        $model = EnergyContractModel::create([
            'name' => $input->name,
            'country_code' => $input->countryCode,
            'zone' => $input->zone,
            'contract_type' => $input->contractType,
            'currency' => $input->currency,
            'pricing_config' => $input->pricingConfig,
            'subscribed_power_kva' => $input->subscribedPowerKva,
            'timezone' => $input->timezone,
            'is_active' => true,
        ]);

        return new EnergyContractRecord(
            id: $model->id,
            contract: EnergyContractMapper::toDomain($model),
            snapshot: EnergyContractMapper::toSnapshotArray($model),
        );
    }
}
