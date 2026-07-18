<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Arbitrage;

use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Asset\PhotovoltaicSystem;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Shared\ValueObject\Power;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArbitrageTestHelpers;

final class SimpleArbitrageEngineTest extends TestCase
{
    use ArbitrageTestHelpers;

    private SimpleArbitrageEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new SimpleArbitrageEngine(lookaheadHours: 6, lookbehindHours: 6);
    }

    public function test_no_pv_and_no_battery_costs_consumption_times_price_every_hour(): void
    {
        $horizon = $this->horizon24h();
        $context = new ArbitrageContext(
            contract: $this->fixedContract(0.20),
            horizon: $horizon,
            prices: PriceSeries::empty('FR'),
            consumption: $this->flatConsumption(1.0),
            pv: $this->noPv(),
            battery: $this->noBattery(),
            maxExportPower: Power::fromKw(3),
        );

        $plan = $this->engine->plan($context);

        self::assertCount(24, $plan->hours());
        self::assertTrue($plan->totalConsumption()->equals(Energy::fromKwh(24)));
        self::assertTrue($plan->totalCost()->equals(Money::of(24 * 0.20)));

        foreach ($plan as $hour) {
            self::assertTrue($hour->consumptionFromGrid()->equals(Energy::fromKwh(1.0)));
            self::assertTrue($hour->batteryCharge()->isZero());
            self::assertTrue($hour->batteryDischarge()->isZero());
            self::assertTrue($hour->exportToGrid()->isZero());
        }
    }

    public function test_pv_only_covers_consumption_and_exports_the_surplus_without_a_battery(): void
    {
        $horizon = $this->horizon24h();
        $context = new ArbitrageContext(
            contract: $this->fixedContract(0.20),
            horizon: $horizon,
            prices: PriceSeries::empty('FR'),
            consumption: $this->flatConsumption(1.0),
            pv: new PhotovoltaicSystem(Power::fromKw(6)),
            battery: $this->noBattery(),
            maxExportPower: Power::fromKw(3),
        );

        $plan = $this->engine->plan($context);

        $noon = $plan->hours()[14];
        self::assertTrue($noon->consumptionFromPv()->isGreaterThan(Energy::zero()));
        self::assertTrue($noon->exportToGrid()->isGreaterThan(Energy::zero()));
        self::assertTrue($noon->batteryCharge()->isZero(), 'no battery means no surplus can ever be stored');

        $midnight = $plan->hours()[2];
        self::assertTrue($midnight->pvProduction()->isZero());
        self::assertTrue($midnight->consumptionFromGrid()->equals(Energy::fromKwh(1.0)));
    }

    public function test_pv_and_battery_charges_on_cheap_surplus_and_discharges_on_the_evening_peak(): void
    {
        // "Duck curve" : creux de prix à midi (coïncide avec le surplus PV),
        // pic en soirée (déficit, plus de PV). C'est le cas d'école qui
        // déclenche à la fois la règle de charge et la règle de décharge de
        // la V1 (cf. SimpleArbitrageEngine, règle "prix courant vs moyenne
        // locale glissante").
        $horizon = $this->horizon24h();
        $prices = [];
        foreach (range(0, 23) as $i) {
            $prices[$i] = match (true) {
                $i >= 11 && $i <= 15 => -10.0,
                $i >= 18 && $i <= 21 => 150.0,
                default => 60.0,
            };
        }

        $context = new ArbitrageContext(
            contract: $this->dynamicSpotContract(),
            horizon: $horizon,
            prices: $this->priceSeriesFromEurPerMwh($horizon, $prices),
            consumption: $this->flatConsumption(1.0),
            pv: new PhotovoltaicSystem(Power::fromKw(6)),
            battery: $this->realBattery(),
            maxExportPower: Power::fromKw(3),
        );

        $plan = $this->engine->plan($context);

        $totalCharge = array_reduce($plan->hours(), fn (Energy $c, $h) => $c->add($h->batteryCharge()), Energy::zero());
        $totalDischarge = array_reduce($plan->hours(), fn (Energy $c, $h) => $c->add($h->batteryDischarge()), Energy::zero());

        self::assertTrue($totalCharge->isGreaterThan(Energy::zero()), 'battery should charge during the cheap midday PV-surplus window');
        self::assertTrue($totalDischarge->isGreaterThan(Energy::zero()), 'battery should discharge during the expensive evening peak');

        $noGridOnlyGridScenario = new ArbitrageContext(
            contract: $this->dynamicSpotContract(),
            horizon: $horizon,
            prices: $this->priceSeriesFromEurPerMwh($horizon, $prices),
            consumption: $this->flatConsumption(1.0),
            pv: $this->noPv(),
            battery: $this->noBattery(),
            maxExportPower: Power::fromKw(3),
        );
        $planWithoutAssets = $this->engine->plan($noGridOnlyGridScenario);

        self::assertTrue(
            $plan->totalCost()->isLessThan($planWithoutAssets->totalCost()),
            'PV + battery must reduce the total cost compared to a grid-only baseline'
        );
    }

    public function test_negative_prices_yield_a_negative_cost_for_the_affected_hours(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../../Fixtures/prices/negative_prices_sample.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $horizon = $this->horizon24h();
        $eurPerMwhByHour = [];
        foreach ($fixture['points'] as $point) {
            $eurPerMwhByHour[$point['hour_index']] = $point['eur_per_mwh'];
        }

        $context = new ArbitrageContext(
            contract: $this->dynamicSpotContract(),
            horizon: $horizon,
            prices: $this->priceSeriesFromEurPerMwh($horizon, $eurPerMwhByHour, $fixture['zone']),
            consumption: $this->flatConsumption(1.0),
            pv: $this->noPv(),
            battery: $this->noBattery(),
            maxExportPower: Power::fromKw(3),
        );

        $plan = $this->engine->plan($context);

        // Heure 13 du fixture : -25 EUR/MWh -> le réseau paie le foyer pour
        // consommer, le coût horaire doit donc être négatif.
        $negativeHour = $plan->hours()[13];
        self::assertTrue($negativeHour->pricePerKwh()->isNegative());
        self::assertTrue($negativeHour->cost()->isNegative());

        // Toutes les heures restent équilibrées (conso = réseau + PV + batterie)
        // même en présence de prix négatifs : invariant déjà vérifié par le
        // constructeur de HourlyDecision, on s'assure juste qu'aucune
        // exception n'a été levée pendant le calcul du plan complet.
        self::assertCount(24, $plan->hours());
    }
}
