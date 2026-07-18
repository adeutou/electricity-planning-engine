<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;

/**
 * Pourcentage borné [0, 100], typiquement utilisé pour les seuils de SOC
 * (state of charge) min/max d'une batterie.
 */
final class Percentage
{
    private readonly float $value;

    private function __construct(float $value)
    {
        if ($value < 0.0 || $value > 100.0) {
            throw DomainException::because(
                "Percentage must be between 0 and 100, got {$value}."
            );
        }

        $this->value = round($value, 4);
    }

    public static function of(float $percent): self
    {
        return new self($percent);
    }

    public static function fromFraction(float $fraction): self
    {
        return new self($fraction * 100);
    }

    public function value(): float
    {
        return $this->value;
    }

    public function toFraction(): float
    {
        return $this->value / 100;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function __toString(): string
    {
        return number_format($this->value, 2).'%';
    }
}
