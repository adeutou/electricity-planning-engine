<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use PHPUnit\Framework\TestCase;

final class EnergyTest extends TestCase
{
    public function test_it_adds_and_subtracts(): void
    {
        $a = Energy::fromKwh(2.5);
        $b = Energy::fromKwh(1.5);

        self::assertTrue($a->add($b)->equals(Energy::fromKwh(4.0)));
        self::assertTrue($a->subtract($b)->equals(Energy::fromKwh(1.0)));
    }

    public function test_it_rejects_negative_values(): void
    {
        $this->expectException(DomainException::class);

        Energy::fromKwh(-0.5);
    }

    public function test_subtract_rejects_a_result_that_would_be_negative(): void
    {
        $this->expectException(DomainException::class);

        Energy::fromKwh(1.0)->subtract(Energy::fromKwh(2.0));
    }

    public function test_min_and_max(): void
    {
        $a = Energy::fromKwh(3.0);
        $b = Energy::fromKwh(7.0);

        self::assertTrue(Energy::min($a, $b)->equals($a));
        self::assertTrue(Energy::max($a, $b)->equals($b));
    }

    public function test_zero_is_zero(): void
    {
        self::assertTrue(Energy::zero()->isZero());
        self::assertFalse(Energy::fromKwh(0.001)->isZero());
    }

    public function test_multiply_scales_the_value(): void
    {
        self::assertTrue(Energy::fromKwh(4.0)->multiply(0.5)->equals(Energy::fromKwh(2.0)));
    }
}
