<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_allows_negative_amounts(): void
    {
        // Un coût horaire négatif est un revenu net (heure de prix spot
        // négatif) : contrairement à Energy, Money doit accepter le signe.
        $revenue = Money::of(-3.5);

        self::assertTrue($revenue->isNegative());
        self::assertSame(-3.5, $revenue->amount());
    }

    public function test_times_energy_computes_an_absolute_cost(): void
    {
        $pricePerKwh = Money::of(0.20);

        self::assertTrue($pricePerKwh->timesEnergy(Energy::fromKwh(10))->equals(Money::of(2.0)));
    }

    public function test_it_rejects_combining_different_currencies(): void
    {
        $this->expectException(DomainException::class);

        Money::of(10, 'EUR')->add(Money::of(5, 'USD'));
    }

    public function test_min_and_max(): void
    {
        $low = Money::of(-5.0);
        $high = Money::of(12.0);

        self::assertTrue(Money::min($low, $high)->equals($low));
        self::assertTrue(Money::max($low, $high)->equals($high));
    }
}
