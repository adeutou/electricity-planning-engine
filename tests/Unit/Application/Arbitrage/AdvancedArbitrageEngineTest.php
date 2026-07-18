<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Arbitrage;

use App\Application\Arbitrage\Engine\AdvancedArbitrageEngine;
use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Power;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArbitrageTestHelpers;

final class AdvancedArbitrageEngineTest extends TestCase
{
    use ArbitrageTestHelpers;

    public function test_v2_reserves_battery_capacity_for_the_real_daily_peak_while_v1_wastes_it_locally(): void
    {
        $horizon = $this->horizon24h();

        // Batterie volontairement petite : une seule "unité" de décharge
        // possible (1 kWh utile), jamais rechargée puisque PV = 0. Le seul
        // enjeu est de savoir SUR QUELLE heure ce kWh est dépensé.
        $battery = $this->realBattery(
            capacityKwh: 2.0,
            maxPowerKw: 5.0,
            roundTripEfficiency: 1.0,
            socMinPercent: 0.0,
            socMaxPercent: 100.0,
            initialSocPercent: 50.0,
        );

        // Prix construits pour piéger la règle locale de V1 : un pic
        // modéré (45) entouré d'un plancher local (40) déclenche une
        // décharge V1 dès l'heure 9 (moyenne des 6h précédentes = 40 < 45).
        // Le vrai pic de la journée (200) arrive à l'heure 20, trop tard
        // pour que V1 en profite : la batterie est déjà vide.
        $eurPerMwhByHour = [];
        foreach (range(0, 23) as $i) {
            $eurPerMwhByHour[$i] = match (true) {
                $i >= 3 && $i <= 8 => 40.0,
                $i === 9 => 45.0,
                $i === 20 => 200.0,
                default => 50.0,
            };
        }

        $context = new ArbitrageContext(
            contract: $this->dynamicSpotContract(),
            horizon: $horizon,
            prices: $this->priceSeriesFromEurPerMwh($horizon, $eurPerMwhByHour),
            consumption: $this->flatConsumption(1.0),
            pv: $this->noPv(),
            battery: $battery,
            maxExportPower: Power::fromKw(3),
        );

        $planV1 = (new SimpleArbitrageEngine(lookaheadHours: 6, lookbehindHours: 6))->plan($context);
        $planV2 = (new AdvancedArbitrageEngine())->plan($context);

        self::assertTrue(
            $planV1->hours()[9]->batteryDischarge()->isGreaterThan(Energy::zero()),
            'V1 discharges at hour 9, triggered by its local lookbehind average'
        );
        self::assertTrue(
            $planV1->hours()[20]->batteryDischarge()->isZero(),
            'V1 has nothing left for hour 20, the real daily peak'
        );

        self::assertTrue(
            $planV2->hours()[9]->batteryDischarge()->isZero(),
            'V2 does not waste its capacity at hour 9'
        );
        self::assertTrue(
            $planV2->hours()[20]->batteryDischarge()->isGreaterThan(Energy::zero()),
            'V2 reserves its capacity for hour 20, ranked globally across the whole horizon'
        );

        self::assertTrue(
            $planV2->totalCost()->isLessThan($planV1->totalCost()),
            'V2 total cost must be strictly lower than V1 on this scenario'
        );
    }

    public function test_v2_applies_a_forecast_safety_margin_to_pv_and_consumption(): void
    {
        // Avec une marge de prudence de 100% sur le PV, le moteur planifie
        // comme si la production PV prévue était nulle : il ne devrait donc
        // jamais décider de charger sur la base d'un surplus PV anticipé,
        // même si la production réelle (utilisée pour le dispatch physique)
        // est non nulle.
        $engine = new AdvancedArbitrageEngine(pvForecastSafetyMargin: 1.0, consumptionForecastSafetyMargin: 0.0);

        $horizon = $this->horizon24h();
        $context = new ArbitrageContext(
            contract: $this->fixedContract(0.20),
            horizon: $horizon,
            prices: \App\Domain\Pricing\PriceSeries::empty('FR'),
            consumption: $this->flatConsumption(1.0),
            pv: new \App\Domain\Asset\PhotovoltaicSystem(Power::fromKw(6)),
            battery: $this->realBattery(),
            maxExportPower: Power::fromKw(3),
        );

        $plan = $engine->plan($context);

        $totalCharge = array_reduce($plan->hours(), fn (Energy $c, $h) => $c->add($h->batteryCharge()), Energy::zero());
        self::assertTrue($totalCharge->isZero(), 'a 100% PV forecast safety margin must suppress any planned charge');
    }
}
