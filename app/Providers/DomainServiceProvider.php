<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Arbitrage\SimulateArbitrageUseCase;
use App\Application\Ports\EnergyContractRepositoryInterface;
use App\Application\Ports\PricePointRepositoryInterface;
use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Domain\Pricing\PriceProviderInterface;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentEnergyContractRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentPricePointRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentSimulationPlanRepository;
use App\Infrastructure\PriceProvider\CachingPriceProvider;
use App\Infrastructure\PriceProvider\PriceProviderFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Point de câblage unique entre les ports du Domain/Application et leurs
 * implémentations Infrastructure. Aucune autre classe de l'application ne
 * devrait instancier directement un repository Eloquent ou un price
 * provider concret : tout passe par ces interfaces, résolues ici.
 *
 * Note : ArbitrageEngineInterface (Simple vs Advanced) n'est volontairement
 * PAS bindée ici — le choix se fait au moment de la requête (paramètre
 * "mode" de /api/simulate), pas via un binding statique du container. Cette
 * résolution contextuelle est la responsabilité de SimulateArbitrageUseCase.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SimulationPlanRepositoryInterface::class, EloquentSimulationPlanRepository::class);
        $this->app->bind(PricePointRepositoryInterface::class, EloquentPricePointRepository::class);
        $this->app->bind(EnergyContractRepositoryInterface::class, EloquentEnergyContractRepository::class);

        $this->app->singleton(PriceProviderInterface::class, function (Application $app): PriceProviderInterface {
            /** @var array<string, mixed> $energyConfig */
            $energyConfig = $app->make('config')->get('energy');

            return new CachingPriceProvider(
                inner: PriceProviderFactory::make($energyConfig),
                cache: $app->make(PricePointRepositoryInterface::class),
                source: (string) ($energyConfig['price_provider'] ?? 'mock'),
            );
        });

        // SimulateArbitrageUseCase attend un tableau brut (config('energy'))
        // plutôt que d'appeler config() lui-même, pour rester testable sans
        // bootstrap Laravel — ce binding explicite fait le pont.
        $this->app->bind(SimulateArbitrageUseCase::class, function (Application $app): SimulateArbitrageUseCase {
            return new SimulateArbitrageUseCase(
                contracts: $app->make(EnergyContractRepositoryInterface::class),
                plans: $app->make(SimulationPlanRepositoryInterface::class),
                defaultPriceProvider: $app->make(PriceProviderInterface::class),
                energyConfig: $app->make('config')->get('energy'),
            );
        });
    }
}
