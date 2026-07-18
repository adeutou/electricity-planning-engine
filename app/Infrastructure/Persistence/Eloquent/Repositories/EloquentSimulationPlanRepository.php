<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;
use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Mappers\SimulationPlanMapper;
use App\Infrastructure\Persistence\Eloquent\Models\SimulationPlanModel;
use Illuminate\Support\Facades\DB;

final class EloquentSimulationPlanRepository implements SimulationPlanRepositoryInterface
{
    public function save(SimulationPlanRecord $record): string
    {
        return DB::transaction(function () use ($record): string {
            $model = SimulationPlanModel::create(SimulationPlanMapper::toModelAttributes($record));

            $model->hours()->createMany(
                SimulationPlanMapper::toHourModelAttributesList($record->plan)
            );

            return $model->id;
        });
    }

    public function findById(string $id): ?SimulationPlanRecord
    {
        $model = SimulationPlanModel::with('hours')->find($id);

        if ($model === null) {
            return null;
        }

        return SimulationPlanMapper::toDomainRecord($model);
    }
}
