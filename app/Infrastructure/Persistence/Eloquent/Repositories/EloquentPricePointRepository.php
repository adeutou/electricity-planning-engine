<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Ports\PricePointRepositoryInterface;
use App\Domain\Pricing\PriceSeries;
use App\Infrastructure\Persistence\Eloquent\Mappers\PricePointMapper;
use App\Infrastructure\Persistence\Eloquent\Models\PricePointModel;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class EloquentPricePointRepository implements PricePointRepositoryInterface
{
    public function findSeries(string $zone, DateTimeInterface $from, DateTimeInterface $to, string $source): ?PriceSeries
    {
        $expectedHours = (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 3600);

        // Les timestamps sont stockés en UTC (cf. PricePointMapper) : on doit
        // comparer avec des bornes elles-mêmes normalisées en UTC, sinon la
        // clause WHERE compare des chaînes naïves dans deux fuseaux différents.
        $utc = new DateTimeZone('UTC');
        $fromUtc = DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $toUtc = DateTimeImmutable::createFromInterface($to)->setTimezone($utc);

        $models = PricePointModel::query()
            ->where('zone', $zone)
            ->where('source', $source)
            ->where('timestamp', '>=', $fromUtc)
            ->where('timestamp', '<', $toUtc)
            ->orderBy('timestamp')
            ->get();

        // Cache incomplet (jamais rempli, ou horizon partiellement couvert) :
        // traité comme une absence de cache plutôt que fusionné avec un
        // appel provider partiel — simplification V1 assumée (voir
        // PricePointRepositoryInterface::findSeries).
        if ($models->count() < $expectedHours) {
            return null;
        }

        $points = $models
            ->map(static fn (PricePointModel $model) => PricePointMapper::toDomain($model))
            ->all();

        return new PriceSeries($zone, $points);
    }

    public function store(PriceSeries $series): void
    {
        $utc = new DateTimeZone('UTC');

        foreach ($series as $point) {
            PricePointModel::updateOrCreate(
                [
                    'zone' => $point->zone(),
                    'source' => $point->source(),
                    'timestamp' => $point->timestamp()->setTimezone($utc),
                ],
                PricePointMapper::toModelAttributes($point)
            );
        }
    }
}
