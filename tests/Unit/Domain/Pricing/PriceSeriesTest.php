<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PriceSeriesTest extends TestCase
{
    public function test_price_at_looks_up_the_matching_hour(): void
    {
        $hour = new DateTimeImmutable('2026-07-18 12:00:00', new DateTimeZone('UTC'));
        $series = new PriceSeries('FR', [
            new PricePoint($hour, Money::of(65.0), 'FR', 'test'),
        ]);

        self::assertTrue($series->priceAt($hour)->equals(Money::of(0.065)));
    }

    public function test_price_at_matches_the_same_instant_regardless_of_the_timezone_object_used(): void
    {
        // Régression : un DateTimeImmutable représentant le MÊME instant
        // mais construit avec un fuseau différent (typique après un
        // aller-retour base de données, où l'app stocke en UTC alors que le
        // contrat raisonne en Europe/Paris) doit être reconnu comme la même
        // heure. Avant le correctif (indexation par timestamp Unix plutôt
        // que par chaîne ISO avec offset), cette assertion levait un faux
        // "prix introuvable".
        $parisHour = new DateTimeImmutable('2026-07-18 14:00:00', new DateTimeZone('Europe/Paris'));
        $series = new PriceSeries('FR', [
            new PricePoint($parisHour, Money::of(65.0), 'FR', 'test'),
        ]);

        $sameInstantInUtc = $parisHour->setTimezone(new DateTimeZone('UTC'));

        self::assertTrue($series->has($sameInstantInUtc));
        self::assertTrue($series->priceAt($sameInstantInUtc)->equals(Money::of(0.065)));
    }

    public function test_price_at_throws_when_the_hour_is_missing(): void
    {
        $series = PriceSeries::empty('FR');

        $this->expectException(DomainException::class);

        $series->priceAt(new DateTimeImmutable('2026-07-18 12:00:00'));
    }

    public function test_average_min_and_max(): void
    {
        $base = new DateTimeImmutable('2026-07-18 00:00:00', new DateTimeZone('UTC'));
        $series = new PriceSeries('FR', [
            new PricePoint($base, Money::of(-20.0), 'FR', 'test'),
            new PricePoint($base->modify('+1 hour'), Money::of(40.0), 'FR', 'test'),
            new PricePoint($base->modify('+2 hours'), Money::of(100.0), 'FR', 'test'),
        ]);

        self::assertTrue($series->average()->equals(Money::of(0.040)));
        self::assertTrue($series->min()->equals(Money::of(-0.020)));
        self::assertTrue($series->max()->equals(Money::of(0.100)));
    }

    public function test_slice_returns_only_points_within_the_window(): void
    {
        $base = new DateTimeImmutable('2026-07-18 00:00:00', new DateTimeZone('UTC'));
        $series = new PriceSeries('FR', [
            new PricePoint($base, Money::of(10.0), 'FR', 'test'),
            new PricePoint($base->modify('+1 hour'), Money::of(20.0), 'FR', 'test'),
            new PricePoint($base->modify('+2 hours'), Money::of(30.0), 'FR', 'test'),
        ]);

        $slice = $series->slice($base->modify('+1 hour'), $base->modify('+2 hours'));

        self::assertSame(1, $slice->count());
        self::assertTrue($slice->priceAt($base->modify('+1 hour'))->equals(Money::of(0.020)));
    }

    public function test_negative_prices_are_preserved(): void
    {
        $hour = new DateTimeImmutable('2026-07-18 03:00:00', new DateTimeZone('UTC'));
        $series = new PriceSeries('FR', [
            new PricePoint($hour, Money::of(-50.0), 'FR', 'test'),
        ]);

        self::assertTrue($series->priceAt($hour)->isNegative());
    }

    public function test_it_rejects_points_from_a_different_zone(): void
    {
        $this->expectException(DomainException::class);

        new PriceSeries('FR', [
            new PricePoint(new DateTimeImmutable(), Money::of(10.0), 'BE', 'test'),
        ]);
    }
}
