<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Percentage;

/**
 * État de charge d'une batterie : niveau d'énergie stocké (kWh) relatif à
 * une capacité totale. Sert de VO pivot entre les kWh bruts manipulés par
 * les calculs (Battery::charge/discharge) et le pourcentage plus parlant
 * pour l'API et les règles métier (SOC min/max).
 */
final class StateOfCharge
{
    private function __construct(
        private readonly Energy $level,
        private readonly Energy $capacity,
    ) {
        if ($level->isGreaterThan($capacity)) {
            throw DomainException::because(
                "State of charge level ({$level}) cannot exceed capacity ({$capacity})."
            );
        }
    }

    public static function of(Energy $level, Energy $capacity): self
    {
        return new self($level, $capacity);
    }

    public static function fromPercentage(Percentage $percentage, Energy $capacity): self
    {
        return new self($capacity->multiply($percentage->toFraction()), $capacity);
    }

    public function level(): Energy
    {
        return $this->level;
    }

    public function capacity(): Energy
    {
        return $this->capacity;
    }

    public function percentage(): Percentage
    {
        if ($this->capacity->isZero()) {
            return Percentage::of(0.0);
        }

        return Percentage::of(($this->level->kwh() / $this->capacity->kwh()) * 100);
    }

    public function withLevel(Energy $newLevel): self
    {
        return new self($newLevel, $this->capacity);
    }

    public function isAtOrBelow(Percentage $threshold): bool
    {
        return $this->percentage()->value() <= $threshold->value() + 1e-6;
    }

    public function isAtOrAbove(Percentage $threshold): bool
    {
        return $this->percentage()->value() >= $threshold->value() - 1e-6;
    }
}
