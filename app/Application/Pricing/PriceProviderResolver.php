<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Domain\Pricing\PriceProviderInterface;
use App\Infrastructure\PriceProvider\PriceProviderFactory;

/**
 * Résout le PriceProviderInterface à utiliser pour un appel donné : le
 * provider par défaut (singleton mis en cache, cf. DomainServiceProvider) si
 * aucun override n'est demandé, sinon une instance dédiée construite à la
 * volée pour le driver demandé. Partagé par SimulateArbitrageUseCase et
 * FetchPriceSeriesUseCase pour éviter de dupliquer cette résolution.
 *
 * Un override explicite n'est volontairement pas mis en cache (pas de
 * CachingPriceProvider) : c'est un choix ponctuel de l'appelant, pas le
 * chemin "normal" pour lequel le cache apporte de la valeur.
 */
final class PriceProviderResolver
{
    /**
     * @param  array<string, mixed>  $energyConfig
     */
    public function __construct(
        private readonly PriceProviderInterface $default,
        private readonly array $energyConfig,
    ) {}

    public function resolve(?string $override): PriceProviderInterface
    {
        if ($override === null) {
            return $this->default;
        }

        return PriceProviderFactory::make(array_merge($this->energyConfig, ['price_provider' => $override]));
    }
}
