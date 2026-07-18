<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Eloquent pour `price_points` (cache des prix de marché).
 *
 * @property int $id
 * @property string $zone
 * @property string $source
 * @property \Illuminate\Support\Carbon $timestamp
 * @property int $resolution_minutes
 * @property float $price_eur_per_mwh
 * @property string $currency
 * @property \Illuminate\Support\Carbon|null $retrieved_at
 */
final class PricePointModel extends Model
{
    protected $table = 'price_points';

    protected $fillable = [
        'zone',
        'source',
        'timestamp',
        'resolution_minutes',
        'price_eur_per_mwh',
        'currency',
        'retrieved_at',
    ];

    protected $casts = [
        'timestamp' => 'immutable_datetime',
        'resolution_minutes' => 'integer',
        'price_eur_per_mwh' => 'float',
        'retrieved_at' => 'immutable_datetime',
    ];
}
