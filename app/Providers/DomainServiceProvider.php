<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Arbitrage\SimulateArbitrageUseCase;
use App\Application\Ports\EnergyContractRepositoryInterface;
use App\Application\Ports\HomeAssistantExporterInterface;
use App\Application\Ports\PricePointRepositoryInterface;
use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Application\Pricing\FetchPriceSeriesUseCase;
use App\Application\Pricing\PriceProviderResolver;
use App\Domain\Pricing\PriceProviderInterface;
use App\Infrastructure\HomeAssistant\HomeAssistantWebhookExporter;
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

        $this->app->singleton(PriceProviderResolver::class, function (Application $app): PriceProviderResolver {
            return new PriceProviderResolver(
                default: $app->make(PriceProviderInterface::class),
                energyConfig: $app->make('config')->get('energy'),
            );
        });

        // SimulateArbitrageUseCase / FetchPriceSeriesUseCase attendent un
        // tableau brut (config('energy')) plutôt que d'appeler config()
        // elles-mêmes, pour rester testables sans bootstrap Laravel — ces
        // bindings explicites font le pont.
        $this->app->bind(SimulateArbitrageUseCase::class, function (Application $app): SimulateArbitrageUseCase {
            return new SimulateArbitrageUseCase(
                contracts: $app->make(EnergyContractRepositoryInterface::class),
                plans: $app->make(SimulationPlanRepositoryInterface::class),
                priceProviders: $app->make(PriceProviderResolver::class),
                energyConfig: $app->make('config')->get('energy'),
            );
        });

        $this->app->bind(FetchPriceSeriesUseCase::class, function (Application $app): FetchPriceSeriesUseCase {
            return new FetchPriceSeriesUseCase(
                priceProviders: $app->make(PriceProviderResolver::class),
            );
        });

        $this->app->bind(HomeAssistantExporterInterface::class, function (Application $app): HomeAssistantExporterInterface {
            /** @var array<string, mixed> $homeAssistantConfig */
            $homeAssistantConfig = $app->make('config')->get('energy.home_assistant');

            return new HomeAssistantWebhookExporter(
                webhookUrl: $homeAssistantConfig['webhook_url'] ?: null,
            );
        });
    }
}
