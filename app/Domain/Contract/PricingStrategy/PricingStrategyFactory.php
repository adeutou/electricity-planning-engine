<?php

declare(strict_types=1);

namespace App\Domain\Contract\PricingStrategy;

use App\Domain\Contract\Enum\ContractType;
use App\Domain\Contract\Enum\SpecialDayColor;
use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Contract\ValueObject\SeasonalTariff;
use App\Domain\Contract\ValueObject\SpecialDayRule;
use App\Domain\Contract\ValueObject\TariffRate;
use App\Domain\Contract\ValueObject\TimeSlot;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Shared\ValueObject\Percentage;
use DateTimeImmutable;

/**
 * Reconstruit une PricingStrategyInterface à partir de la configuration JSON
 * brute stockée dans `energy_contracts.pricing_config` (voir migration
 * correspondante). Cette hydratation reste dans le Domain — et non dans
 * l'Infrastructure — car elle ne fait qu'assembler des value objects du
 * domaine à partir de types primitifs ; c'est la même responsabilité qu'un
 * constructeur, juste plus tolérante en entrée (tableau non typé venant de
 * la base plutôt qu'arguments PHP typés).
 *
 * Formats attendus par type de contrat (voir docs/domain-model.md) :
 *
 * fixed:          { price_per_kwh, currency? }
 * peak_off_peak:  { off_peak_slots: [{start,end}], seasons: [{label, months, rates:[{slot,price_per_kwh}]}], currency? }
 * tempo:          { off_peak_slots: [...], default_color?, calendar: [{date,color}], rates: {blue:[...], white:[...], red:[...]}, currency? }
 * dynamic_spot:   { supplier_fee_per_kwh, supplier_margin_percent, currency? }
 */
final class PricingStrategyFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(ContractType $type, array $config): PricingStrategyInterface
    {
        $currency = (string) ($config['currency'] ?? 'EUR');

        return match ($type) {
            ContractType::Fixed => self::buildFixed($config, $currency),
            ContractType::PeakOffPeak => self::buildPeakOffPeak($config, $currency),
            ContractType::Tempo => self::buildTempo($config, $currency),
            ContractType::DynamicSpot => self::buildDynamicSpot($config, $currency),
        };
    }

    private static function buildFixed(array $config, string $currency): FixedPricingStrategy
    {
        return new FixedPricingStrategy(
            Money::of(self::requireFloat($config, 'price_per_kwh'), $currency)
        );
    }

    private static function buildPeakOffPeak(array $config, string $currency): PeakOffPeakPricingStrategy
    {
        $offPeakSlots = self::parseTimeSlots($config['off_peak_slots'] ?? [], TimeSlotType::OffPeak);
        $seasons = array_map(
            fn (array $season) => self::parseSeason($season, $currency),
            self::requireArray($config, 'seasons')
        );

        return new PeakOffPeakPricingStrategy($offPeakSlots, $seasons);
    }

    private static function buildTempo(array $config, string $currency): TempoPricingStrategy
    {
        $offPeakSlots = self::parseTimeSlots($config['off_peak_slots'] ?? [], TimeSlotType::OffPeak);
        $calendar = array_map(
            fn (array $entry) => self::parseSpecialDayRule($entry),
            $config['calendar'] ?? []
        );

        $ratesByColor = [];
        foreach (self::requireArray($config, 'rates') as $colorValue => $rates) {
            $ratesByColor[$colorValue] = self::parseRates($rates, $currency);
        }

        $defaultColor = isset($config['default_color'])
            ? SpecialDayColor::from((string) $config['default_color'])
            : SpecialDayColor::Blue;

        return new TempoPricingStrategy($calendar, $offPeakSlots, $ratesByColor, $defaultColor);
    }

    private static function buildDynamicSpot(array $config, string $currency): DynamicSpotPricingStrategy
    {
        return new DynamicSpotPricingStrategy(
            Money::of(self::requireFloat($config, 'supplier_fee_per_kwh'), $currency),
            Percentage::of((float) ($config['supplier_margin_percent'] ?? 0.0)),
        );
    }

    /**
     * @param  list<array{start:string,end:string}>  $slots
     * @return list<TimeSlot>
     */
    private static function parseTimeSlots(array $slots, TimeSlotType $type): array
    {
        return array_map(
            fn (array $slot) => new TimeSlot($type, (string) $slot['start'], (string) $slot['end']),
            $slots
        );
    }

    /**
     * @param  array<string, mixed>  $season
     */
    private static function parseSeason(array $season, string $currency): SeasonalTariff
    {
        return new SeasonalTariff(
            (string) self::requireValue($season, 'label'),
            array_map(intval(...), self::requireArray($season, 'months')),
            self::parseRates(self::requireArray($season, 'rates'), $currency),
        );
    }

    /**
     * @param  list<array{slot:string,price_per_kwh:float}>  $rates
     * @return list<TariffRate>
     */
    private static function parseRates(array $rates, string $currency): array
    {
        return array_map(
            fn (array $rate) => new TariffRate(
                TimeSlotType::from((string) $rate['slot']),
                Money::of((float) $rate['price_per_kwh'], $currency),
            ),
            $rates
        );
    }

    /**
     * @param  array{date:string,color:string}  $entry
     */
    private static function parseSpecialDayRule(array $entry): SpecialDayRule
    {
        return new SpecialDayRule(
            new DateTimeImmutable((string) $entry['date']),
            SpecialDayColor::from((string) $entry['color']),
        );
    }

    private static function requireFloat(array $config, string $key): float
    {
        return (float) self::requireValue($config, $key);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $config, string $key): array
    {
        $value = self::requireValue($config, $key);

        if (! is_array($value)) {
            throw DomainException::because("Pricing config key '{$key}' must be an array.");
        }

        return $value;
    }

    private static function requireValue(array $config, string $key): mixed
    {
        if (! array_key_exists($key, $config)) {
            throw DomainException::because("Pricing config is missing required key '{$key}'.");
        }

        return $config[$key];
    }
}
