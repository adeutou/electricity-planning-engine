<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Contract;

use App\Domain\Contract\Enum\ContractType;
use App\Domain\Contract\Enum\SpecialDayColor;
use App\Domain\Contract\PricingStrategy\PricingStrategyFactory;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PricingStrategyTest extends TestCase
{
    public function test_fixed_strategy_returns_the_same_price_at_any_hour(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::Fixed, ['price_per_kwh' => 0.22]);

        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-01-01 03:00:00'))->equals(Money::of(0.22)));
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-07-14 18:00:00'))->equals(Money::of(0.22)));
    }

    public function test_peak_off_peak_strategy_resolves_the_correct_rate(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::PeakOffPeak, [
            'off_peak_slots' => [['start' => '22:00', 'end' => '06:00']],
            'seasons' => [[
                'label' => 'year_round',
                'months' => range(1, 12),
                'rates' => [
                    ['slot' => 'peak', 'price_per_kwh' => 0.27],
                    ['slot' => 'off_peak', 'price_per_kwh' => 0.20],
                ],
            ]],
        ]);

        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-07-18 14:00:00'))->equals(Money::of(0.27)));
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-07-18 23:00:00'))->equals(Money::of(0.20)));
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-07-18 05:00:00'))->equals(Money::of(0.20)));
    }

    public function test_peak_off_peak_strategy_supports_seasonal_rates(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::PeakOffPeak, [
            'off_peak_slots' => [],
            'seasons' => [
                ['label' => 'winter', 'months' => [12, 1, 2], 'rates' => [['slot' => 'peak', 'price_per_kwh' => 0.30]]],
                ['label' => 'summer', 'months' => [6, 7, 8], 'rates' => [['slot' => 'peak', 'price_per_kwh' => 0.18]]],
            ],
        ]);

        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-01-15 12:00:00'))->equals(Money::of(0.30)));
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-07-15 12:00:00'))->equals(Money::of(0.18)));
    }

    public function test_tempo_strategy_uses_the_calendar_color_for_the_day(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::Tempo, [
            'off_peak_slots' => [['start' => '22:00', 'end' => '06:00']],
            'default_color' => 'blue',
            'calendar' => [
                ['date' => '2026-01-15', 'color' => 'red'],
            ],
            'rates' => [
                'blue' => [['slot' => 'peak', 'price_per_kwh' => 0.16], ['slot' => 'off_peak', 'price_per_kwh' => 0.13]],
                'white' => [['slot' => 'peak', 'price_per_kwh' => 0.19], ['slot' => 'off_peak', 'price_per_kwh' => 0.15]],
                'red' => [['slot' => 'peak', 'price_per_kwh' => 0.75], ['slot' => 'off_peak', 'price_per_kwh' => 0.16]],
            ],
        ]);

        // Jour non listé dans le calendrier -> couleur par défaut (bleu).
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-03-01 14:00:00'))->equals(Money::of(0.16)));
        // Jour rouge explicite dans le calendrier -> tarif "jour d'effacement".
        self::assertTrue($strategy->priceForHour(new DateTimeImmutable('2026-01-15 14:00:00'))->equals(Money::of(0.75)));
    }

    public function test_tempo_strategy_requires_rates_for_every_color(): void
    {
        $this->expectException(DomainException::class);

        PricingStrategyFactory::fromConfig(ContractType::Tempo, [
            'off_peak_slots' => [],
            'calendar' => [],
            'rates' => [
                'blue' => [['slot' => 'peak', 'price_per_kwh' => 0.16]],
                // 'white' et 'red' manquants.
            ],
        ]);
    }

    public function test_dynamic_spot_strategy_applies_margin_and_fee_over_the_wholesale_price(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::DynamicSpot, [
            'supplier_fee_per_kwh' => 0.03,
            'supplier_margin_percent' => 10.0,
        ]);

        $hour = new DateTimeImmutable('2026-07-18 14:00:00');
        $marketPrices = new PriceSeries('FR', [
            new PricePoint($hour, Money::of(30.0), 'FR', 'test'), // 30 EUR/MWh = 0.03 EUR/kWh
        ]);

        // 0.03 * 1.10 + 0.03 = 0.063
        $price = $strategy->priceForHour($hour, $marketPrices);
        self::assertEqualsWithDelta(0.063, $price->amount(), 1e-9);
    }

    public function test_dynamic_spot_strategy_requires_market_prices(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::DynamicSpot, [
            'supplier_fee_per_kwh' => 0.0,
            'supplier_margin_percent' => 0.0,
        ]);

        $this->expectException(DomainException::class);

        $strategy->priceForHour(new DateTimeImmutable('2026-07-18 14:00:00'), null);
    }

    public function test_dynamic_spot_strategy_passes_through_negative_wholesale_prices(): void
    {
        $strategy = PricingStrategyFactory::fromConfig(ContractType::DynamicSpot, [
            'supplier_fee_per_kwh' => 0.0,
            'supplier_margin_percent' => 0.0,
        ]);

        $hour = new DateTimeImmutable('2026-07-18 13:00:00');
        $marketPrices = new PriceSeries('FR', [
            new PricePoint($hour, Money::of(-40.0), 'FR', 'test'),
        ]);

        self::assertTrue($strategy->priceForHour($hour, $marketPrices)->isNegative());
    }

    public function test_special_day_color_enum_has_the_three_tempo_colors(): void
    {
        self::assertCount(3, SpecialDayColor::cases());
    }
}
