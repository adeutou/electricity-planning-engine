<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chart;

use App\Domain\Arbitrage\ArbitragePlan;
use App\Domain\Arbitrage\HourlyDecision;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Chart\SvgArbitrageChartRenderer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SvgArbitrageChartRendererTest extends TestCase
{
    private function hour(
        int $index,
        float $price,
        float $consumption,
        float $pv,
        float $fromGrid,
        float $fromPv,
        float $fromBattery,
        float $charge,
        float $discharge,
        float $export,
        float $soc,
        float $cost,
    ): HourlyDecision {
        return new HourlyDecision(
            hourIndex: $index,
            startsAt: new DateTimeImmutable('2026-07-20 00:00:00 UTC'),
            pricePerKwh: Money::of($price),
            consumption: Energy::fromKwh($consumption),
            pvProduction: Energy::fromKwh($pv),
            consumptionFromGrid: Energy::fromKwh($fromGrid),
            consumptionFromPv: Energy::fromKwh($fromPv),
            consumptionFromBattery: Energy::fromKwh($fromBattery),
            batteryCharge: Energy::fromKwh($charge),
            batteryDischarge: Energy::fromKwh($discharge),
            exportToGrid: Energy::fromKwh($export),
            socEndOfHour: Energy::fromKwh($soc),
            cost: Money::of($cost),
        );
    }

    public function test_it_renders_a_well_formed_svg_document(): void
    {
        $plan = new ArbitragePlan('FR', 'simple', [
            $this->hour(0, -0.02, 1.0, 3.0, 0.0, 1.0, 0.0, 1.0, 0.0, 1.0, 6.0, -0.02),
            $this->hour(1, 0.25, 1.0, 0.0, 0.5, 0.0, 0.5, 0.0, 0.5, 0.0, 5.5, 0.125),
        ]);

        $svg = (new SvgArbitrageChartRenderer())->render($plan);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringContainsString('Zone FR', $svg);
        self::assertStringContainsString('polyline', $svg);
    }

    public function test_it_handles_an_empty_plan_without_errors(): void
    {
        $plan = new ArbitragePlan('FR', 'simple', []);

        $svg = (new SvgArbitrageChartRenderer())->render($plan);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    public function test_it_escapes_text_content(): void
    {
        // zone/mode viennent de valeurs internes contrôlées, mais le
        // renderer ne doit pas produire de XML invalide si un caractère
        // spécial s'y glissait malgré tout.
        $plan = new ArbitragePlan('FR&BE', 'simple', [
            $this->hour(0, 0.20, 1.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.20),
        ]);

        $svg = (new SvgArbitrageChartRenderer())->render($plan);

        self::assertStringContainsString('FR&amp;BE', $svg);
        self::assertStringNotContainsString('FR&BE', $svg);
    }

    public function test_it_does_not_crash_on_negative_prices(): void
    {
        $plan = new ArbitragePlan('FR', 'simple', [
            $this->hour(0, -0.05, 1.0, 2.0, 0.0, 1.0, 0.0, 1.0, 0.0, 0.0, 6.0, -0.05),
        ]);

        $svg = (new SvgArbitrageChartRenderer())->render($plan);

        // La ligne pointillée de référence à zéro n'apparaît que si le prix
        // minimum est négatif (cf. zeroLine()).
        self::assertStringContainsString('stroke-dasharray', $svg);
    }
}
