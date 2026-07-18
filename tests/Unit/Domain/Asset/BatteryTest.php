<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Asset;

use App\Domain\Asset\Battery;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Percentage;
use App\Domain\Shared\ValueObject\Power;
use PHPUnit\Framework\TestCase;

final class BatteryTest extends TestCase
{
    private function battery(
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

    public function test_initial_state_of_charge_matches_the_requested_percentage(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, initialSocPercent: 50.0);

        self::assertEqualsWithDelta(5.0, $battery->stateOfCharge()->level()->kwh(), 1e-6);
    }

    public function test_charge_applies_the_charge_efficiency(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, roundTripEfficiency: 0.9, initialSocPercent: 50.0);

        $charged = $battery->charge(Energy::fromKwh(2.0));

        // Rendement réparti à parts égales entre charge et décharge :
        // sqrt(0.9) de chaque côté, cf. Battery::chargeEfficiency().
        $expectedLevel = 5.0 + 2.0 * sqrt(0.9);
        self::assertEqualsWithDelta($expectedLevel, $charged->stateOfCharge()->level()->kwh(), 1e-6);
    }

    public function test_discharge_requires_more_stored_energy_than_it_delivers(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, roundTripEfficiency: 0.9, initialSocPercent: 50.0);

        $discharged = $battery->discharge(Energy::fromKwh(2.0));

        $expectedLevel = 5.0 - 2.0 / sqrt(0.9);
        self::assertEqualsWithDelta($expectedLevel, $discharged->stateOfCharge()->level()->kwh(), 1e-6);
    }

    public function test_charge_is_capped_by_available_headroom_to_soc_max(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, socMaxPercent: 60.0, initialSocPercent: 50.0, roundTripEfficiency: 1.0);

        // Headroom stocké = 10% de 10 kWh = 1 kWh, donc côté entrée (efficacité
        // 1.0) le maximum chargeable est aussi 1 kWh, indépendamment de la
        // puissance ou de la quantité demandée.
        self::assertEqualsWithDelta(1.0, $battery->maxChargeableEnergy()->kwh(), 1e-6);
    }

    public function test_discharge_is_capped_by_available_headroom_to_soc_min(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, socMinPercent: 40.0, initialSocPercent: 50.0, roundTripEfficiency: 1.0);

        self::assertEqualsWithDelta(1.0, $battery->maxDischargeableEnergy()->kwh(), 1e-6);
    }

    public function test_charge_is_capped_by_max_power(): void
    {
        $battery = $this->battery(capacityKwh: 100.0, maxPowerKw: 3.0, socMinPercent: 0.0, socMaxPercent: 100.0, initialSocPercent: 0.0, roundTripEfficiency: 1.0);

        // Largement assez de headroom (100 kWh) : c'est la puissance max qui
        // borne l'énergie chargeable en une heure, pas la capacité.
        self::assertEqualsWithDelta(3.0, $battery->maxChargeableEnergy()->kwh(), 1e-6);
    }

    public function test_charging_beyond_the_limit_throws(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, initialSocPercent: 95.0, socMaxPercent: 100.0);

        $this->expectException(DomainException::class);

        $battery->charge(Energy::fromKwh(5.0));
    }

    public function test_discharging_beyond_the_limit_throws(): void
    {
        $battery = $this->battery(capacityKwh: 10.0, initialSocPercent: 15.0, socMinPercent: 10.0);

        $this->expectException(DomainException::class);

        $battery->discharge(Energy::fromKwh(5.0));
    }

    public function test_soc_min_must_be_strictly_lower_than_soc_max(): void
    {
        $this->expectException(DomainException::class);

        $this->battery(socMinPercent: 80.0, socMaxPercent: 20.0);
    }

    public function test_battery_is_immutable(): void
    {
        $original = $this->battery(initialSocPercent: 50.0);

        $charged = $original->charge(Energy::fromKwh(1.0));

        self::assertEqualsWithDelta(5.0, $original->stateOfCharge()->level()->kwh(), 1e-6);
        self::assertNotEqualsWithDelta(5.0, $charged->stateOfCharge()->level()->kwh(), 1e-6);
    }
}
