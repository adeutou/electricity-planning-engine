<?php

declare(strict_types=1);

namespace App\Application\Arbitrage;

use App\Application\Arbitrage\DTO\EnergyContractInput;
use App\Application\Arbitrage\DTO\SimulationPlanRecord;
use App\Application\Arbitrage\DTO\SimulationRequestData;
use App\Application\Arbitrage\DTO\SimulationResultData;
use App\Application\Arbitrage\Engine\AdvancedArbitrageEngine;
use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Application\Ports\EnergyContractRepositoryInterface;
use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Arbitrage\ArbitrageEngineInterface;
use App\Domain\Asset\Battery;
use App\Domain\Asset\ConsumptionProfile;
use App\Domain\Asset\PhotovoltaicSystem;
use App\Domain\Asset\ValueObject\HourlyProfile;
use App\Domain\Pricing\PriceProviderInterface;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Percentage;
use App\Domain\Shared\ValueObject\Power;
use App\Domain\Shared\ValueObject\TimeHorizon;
use App\Infrastructure\PriceProvider\PriceProviderFactory;
use InvalidArgumentException;

/**
 * Cas d'usage central du projet : assemble contrat, prix, actifs et horizon
 * à partir d'une requête, exécute le moteur d'arbitrage demandé (V1 ou V2),
 * persiste le résultat et le retourne. C'est le seul point d'entrée que le
 * contrôleur HTTP (SimulationController) appelle — il ne connaît lui-même
 * aucun détail du domaine.
 *
 * $energyConfig est le tableau brut de config('energy'), injecté tel quel
 * (plutôt que d'appeler config() ici) pour que ce use case reste testable
 * sans bootstrap Laravel complet et ignore l'existence du framework — c'est
 * App\Providers\DomainServiceProvider qui fait le pont.
 */
final class SimulateArbitrageUseCase
{
    /**
     * @param  array<string, mixed>  $energyConfig
     */
    public function __construct(
        private readonly EnergyContractRepositoryInterface $contracts,
        private readonly SimulationPlanRepositoryInterface $plans,
        private readonly PriceProviderInterface $defaultPriceProvider,
        private readonly array $energyConfig,
    ) {}

    public function handle(SimulationRequestData $request): SimulationResultData
    {
        $horizon = TimeHorizon::between($request->horizonStart, $request->horizonEnd);

        $contractRecord = $this->contracts->create($this->buildContractInput($request->contract));
        $contract = $contractRecord->contract;

        $priceProviderName = $request->priceProvider ?? (string) ($this->energyConfig['price_provider'] ?? 'mock');
        $priceProvider = $request->priceProvider !== null
            ? PriceProviderFactory::make(array_merge($this->energyConfig, ['price_provider' => $request->priceProvider]))
            : $this->defaultPriceProvider;

        // On n'interroge le provider que si le contrat en a réellement besoin
        // (tarif spot) : inutile de solliciter une API externe pour un
        // contrat fixe ou HP/HC (cf. EnergyContract::requiresMarketPrices()).
        $prices = $contract->requiresMarketPrices()
            ? $priceProvider->getPrices($horizon->start(), $horizon->end(), $contract->zone())
            : PriceSeries::empty($contract->zone());

        $battery = $this->buildBattery($request->battery);
        $pv = $this->buildPv($request->pv);
        $consumption = $this->buildConsumption($request->consumption);
        $maxExportPower = Power::fromKw(
            $request->maxExportPowerKw ?? (float) $this->energyConfig['grid']['max_export_power_kw']
        );

        $context = new ArbitrageContext($contract, $horizon, $prices, $consumption, $pv, $battery, $maxExportPower);

        $plan = $this->resolveEngine($request->mode)->plan($context);

        $id = $this->plans->save(new SimulationPlanRecord(
            id: null,
            plan: $plan,
            energyContractId: $contractRecord->id,
            priceProvider: $priceProviderName,
            horizonStart: $horizon->start(),
            horizonEnd: $horizon->end(),
            timezone: $request->timezone,
            contractSnapshot: $contractRecord->snapshot,
            batteryConfig: $request->battery ?? $this->energyConfig['battery'],
            pvConfig: $request->pv ?? ['peak_power_kwc' => $this->energyConfig['pv']['peak_power_kwc']],
            consumptionConfig: $request->consumption ?? ['daily_baseline_kwh' => $this->energyConfig['consumption']['daily_baseline_kwh']],
        ));

        return new SimulationResultData($id, $plan);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function buildContractInput(array $contract): EnergyContractInput
    {
        return new EnergyContractInput(
            name: (string) ($contract['name'] ?? 'Untitled contract'),
            countryCode: (string) $contract['country_code'],
            zone: (string) $contract['zone'],
            contractType: (string) $contract['contract_type'],
            pricingConfig: $contract['pricing_config'],
            currency: (string) ($contract['currency'] ?? 'EUR'),
            subscribedPowerKva: isset($contract['subscribed_power_kva']) ? (float) $contract['subscribed_power_kva'] : null,
            timezone: (string) ($contract['timezone'] ?? 'Europe/Paris'),
        );
    }

    /**
     * @param  array<string, mixed>|null  $input
     */
    private function buildBattery(?array $input): Battery
    {
        $config = $input ?? [];
        $defaults = $this->energyConfig['battery'];

        return Battery::create(
            capacity: Energy::fromKwh((float) ($config['capacity_kwh'] ?? $defaults['capacity_kwh'])),
            maxChargePower: Power::fromKw((float) ($config['max_charge_power_kw'] ?? $defaults['max_charge_power_kw'])),
            maxDischargePower: Power::fromKw((float) ($config['max_discharge_power_kw'] ?? $defaults['max_discharge_power_kw'])),
            roundTripEfficiency: (float) ($config['round_trip_efficiency'] ?? $defaults['round_trip_efficiency']),
            socMin: Percentage::of((float) ($config['soc_min_percent'] ?? $defaults['soc_min_percent'])),
            socMax: Percentage::of((float) ($config['soc_max_percent'] ?? $defaults['soc_max_percent'])),
            initialSoc: Percentage::of((float) ($config['initial_soc_percent'] ?? $defaults['initial_soc_percent'])),
        );
    }

    /**
     * @param  array<string, mixed>|null  $input
     */
    private function buildPv(?array $input): PhotovoltaicSystem
    {
        $config = $input ?? [];

        $profile = isset($config['hourly_profile_kwh'])
            ? HourlyProfile::fromKwhValues($config['hourly_profile_kwh'])
            : null;

        $peakPowerKwc = (float) ($config['peak_power_kwc'] ?? $this->energyConfig['pv']['peak_power_kwc']);

        return new PhotovoltaicSystem(Power::fromKw($peakPowerKwc), $profile);
    }

    /**
     * @param  array<string, mixed>|null  $input
     */
    private function buildConsumption(?array $input): ConsumptionProfile
    {
        $config = $input ?? [];

        if (isset($config['hourly_profile_kwh'])) {
            return new ConsumptionProfile(profile: HourlyProfile::fromKwhValues($config['hourly_profile_kwh']));
        }

        $dailyBaseline = (float) ($config['daily_baseline_kwh'] ?? $this->energyConfig['consumption']['daily_baseline_kwh']);

        return new ConsumptionProfile(dailyBaseline: Energy::fromKwh($dailyBaseline));
    }

    private function resolveEngine(string $mode): ArbitrageEngineInterface
    {
        $arbitrageConfig = $this->energyConfig['arbitrage'];

        return match ($mode) {
            SimpleArbitrageEngine::MODE => new SimpleArbitrageEngine(
                lookaheadHours: (int) $arbitrageConfig['lookahead_hours'],
                lookbehindHours: (int) $arbitrageConfig['lookbehind_hours'],
            ),
            AdvancedArbitrageEngine::MODE => new AdvancedArbitrageEngine(
                pvForecastSafetyMargin: (float) $arbitrageConfig['pv_forecast_safety_margin'],
                consumptionForecastSafetyMargin: (float) $arbitrageConfig['consumption_forecast_safety_margin'],
            ),
            default => throw new InvalidArgumentException("Unknown arbitrage mode '{$mode}'."),
        };
    }
}
