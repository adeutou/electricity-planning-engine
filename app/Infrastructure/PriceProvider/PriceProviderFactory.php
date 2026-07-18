<?php

declare(strict_types=1);

namespace App\Infrastructure\PriceProvider;

use App\Domain\Pricing\PriceProviderInterface;
use InvalidArgumentException;

/**
 * Résout l'implémentation active de PriceProviderInterface à partir de
 * config('energy'). Point d'extension unique pour brancher un nouveau
 * provider (EPEX, Nord Pool...) : ajouter un `case` ici, pas de changement
 * ailleurs dans l'Application ou le Domain.
 */
final class PriceProviderFactory
{
    /**
     * @param  array<string, mixed>  $energyConfig  Le tableau retourné par config('energy').
     */
    public static function make(array $energyConfig): PriceProviderInterface
    {
        $driver = $energyConfig['price_provider'] ?? 'mock';

        return match ($driver) {
            'mock' => new MockPriceProvider(),
            'entsoe' => new EntsoEPriceProvider(
                baseUrl: $energyConfig['entsoe']['base_url'],
                apiToken: $energyConfig['entsoe']['api_token'] ?: null,
                timeoutSeconds: (int) ($energyConfig['entsoe']['timeout'] ?? 10),
            ),
            default => throw new InvalidArgumentException("Unknown price provider driver '{$driver}'."),
        };
    }
}
