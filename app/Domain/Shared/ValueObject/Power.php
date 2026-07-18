<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;

/**
 * Puissance, en kW, toujours >= 0 (ex. puissance crête PV, puissance max de
 * charge/décharge d'une batterie).
 */
final class Power
{
    private const EPSILON = 1e-6;

    private readonly float $kw;

    private function __construct(float $kw)
    {
        $rounded = round($kw, 6);

        if ($rounded < -self::EPSILON) {
            throw DomainException::because(
                "Power cannot be negative, got {$rounded} kW."
            );
        }

        $this->kw = max(0.0, $rounded);
    }

    public static function fromKw(float $kw): self
    {
        return new self($kw);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public function kw(): float
    {
        return $this->kw;
    }

    /**
     * Énergie délivrable en maintenant cette puissance pendant $hours heures.
     */
    public function toEnergy(float $hours): Energy
    {
        if ($hours < 0) {
            throw DomainException::because('Duration cannot be negative.');
        }

        return Energy::fromKwh($this->kw * $hours);
    }

    public function isZero(): bool
    {
        return abs($this->kw) < self::EPSILON;
    }

    public function isGreaterThan(self $other): bool
    {
        return ($this->kw - $other->kw) > self::EPSILON;
    }

    public static function min(self $a, self $b): self
    {
        return $a->isGreaterThan($b) ? $b : $a;
    }

    public function __toString(): string
    {
        return number_format($this->kw, 3).' kW';
    }
}
