<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Asset\Battery;
use App\Domain\Asset\ConsumptionProfile;
use App\Domain\Asset\ValueObject\HourlyProfile;
use App\Domain\Contract\Enum\ContractType;
use App\Domain\Contract\EnergyContract;
use App\Domain\Contract\PricingStrategy\PricingStrategyFactory;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Shared\ValueObject\Percentage;
use App\Domain\Shared\ValueObject\Power;
use App\Domain\Shared\ValueObject\TimeHorizon;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Constructeurs de scénarios réutilisés par les tests des moteurs
 * d'arbitrage (V1 et V2) : batterie neutralisée, contrats simples, prix
 * synthétiques. Centralisé ici plutôt que dupliqué dans chaque classe de
 * test — c'est exactement le même besoin que SimpleArbitrageEngineTest et
 * AdvancedArbitrageEngineTest partagent.
 */
trait ArbitrageTestHelpers
{
    protected function horizon24h(string $start = '2026-07-20 00:00:00', string $timezone = 'Europe/Paris'): TimeHorizon
    {
        return TimeHorizon::hoursFrom(new DateTimeImmutable($start, new DateTimeZone($timezone)), 24);
    }

    /**
     * Batterie "absente" : capacité nulle, donc chargeHeadroom()/dischargeHeadroom()
     * sont toujours à zéro et aucune charge/décharge n'est jamais possible.
     * Évite de faire des cas particuliers dans les moteurs pour "pas de batterie".
     */
    protected function noBattery(): Battery
    {
        return Battery::create(
            capacity: Energy::zero(),
            maxChargePower: Power::zero(),
            maxDischargePower: Power::zero(),
            roundTripEfficiency: 1.0,
            socMin: Percentage::of(0),
            socMax: Percentage::of(100),
            initialSoc: Percentage::of(0),
        );
    }

    protected function realBattery(
        float $capacityKwh = 10.0,
        float $maxPowerKw = 5.0,
        float $roundTripEfficiency = 0.9,
        float $socMinPercent = 10.0,
        float $socMaxPercent = 100.0,
        float $initialSocPercent = 50.0,
    ): Battery {
        return Battery::create(
            capacity: Energy::fromKwh($capacityKwh),
            maxChargePower: Power::fromKw($maxPowerKw),
            maxDischargePower: Power::fromKw($maxPowerKw),
            roundTripEfficiency: $roundTripEfficiency,
            socMin: Percentage::of($socMinPercent),
            socMax: Percentage::of($socMaxPercent),
            initialSoc: Percentage::of($initialSocPercent),
        );
    }

    protected function noPv(): \App\Domain\Asset\PhotovoltaicSystem
    {
        return new \App\Domain\Asset\PhotovoltaicSystem(Power::zero());
    }

    protected function flatConsumption(float $kwhPerHour, int $hours = 24): ConsumptionProfile
    {
        return new ConsumptionProfile(profile: HourlyProfile::fromKwhValues(array_fill(0, $hours, $kwhPerHour)));
    }

    protected function fixedContract(float $pricePerKwh, string $zone = 'FR'): EnergyContract
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::Fixed, ['price_per_kwh' => $pricePerKwh]);

        return new EnergyContract('Test fixed contract', 'FR', $zone, ContractType::Fixed, $strategy);
    }

    protected function dynamicSpotContract(float $supplierFeePerKwh = 0.0, float $supplierMarginPercent = 0.0, string $zone = 'FR'): EnergyContract
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::DynamicSpot, [
            'supplier_fee_per_kwh' => $supplierFeePerKwh,
            'supplier_margin_percent' => $supplierMarginPercent,
        ]);

        return new EnergyContract('Test spot contract', 'FR', $zone, ContractType::DynamicSpot, $strategy);
    }

    /**
     * @param  array<int, float>  $eurPerMwhByHourIndex  Prix €/MWh indexé par heure (0-based) de l'horizon.
     */
    protected function priceSeriesFromEurPerMwh(TimeHorizon $horizon, array $eurPerMwhByHourIndex, string $zone = 'FR'): PriceSeries
    {
        $points = [];

        foreach ($horizon->iterateHours() as $hourIndex => $hourStart) {
            $eurPerMwh = $eurPerMwhByHourIndex[$hourIndex] ?? 60.0;
            $points[] = new PricePoint($hourStart, Money::of($eurPerMwh), $zone, 'test-fixture');
        }

        return new PriceSeries($zone, $points);
    }
}
