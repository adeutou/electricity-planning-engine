<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;

/**
 * Quantité d'énergie, en kWh, toujours >= 0.
 *
 * Toutes les grandeurs du domaine (consommation, production PV, charge et
 * décharge batterie...) sont des quantités non signées : le sens du flux
 * (vers/depuis quel actif) est porté par le nom du champ qui contient
 * l'Energy (ex. `batteryCharge` vs `batteryDischarge`), pas par le signe de
 * la valeur. Cela évite une classe de bugs fréquente sur ce genre de modèle
 * (confusion de signe entre "j'importe" et "j'exporte").
 */
final class Energy
{
    private const EPSILON = 1e-6;

    private readonly float $kwh;

    private function __construct(float $kwh)
    {
        $rounded = round($kwh, 6);

        if ($rounded < -self::EPSILON) {
            throw DomainException::because(
                "Energy cannot be negative, got {$rounded} kWh."
            );
        }

        $this->kwh = max(0.0, $rounded);
    }

    public static function fromKwh(float $kwh): self
    {
        return new self($kwh);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public function kwh(): float
    {
        return $this->kwh;
    }

    public function add(self $other): self
    {
        return new self($this->kwh + $other->kwh);
    }

    /**
     * @throws DomainException si le résultat serait négatif.
     */
    public function subtract(self $other): self
    {
        return new self($this->kwh - $other->kwh);
    }

    public function multiply(float $factor): self
    {
        return new self($this->kwh * $factor);
    }

    public function isZero(): bool
    {
        return abs($this->kwh) < self::EPSILON;
    }

    public function isGreaterThan(self $other): bool
    {
        return ($this->kwh - $other->kwh) > self::EPSILON;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return ! $other->isGreaterThan($this);
    }

    public function isLessThan(self $other): bool
    {
        return $other->isGreaterThan($this);
    }

    public function equals(self $other): bool
    {
        return abs($this->kwh - $other->kwh) < self::EPSILON;
    }

    public static function min(self $a, self $b): self
    {
        return $a->isGreaterThan($b) ? $b : $a;
    }

    public static function max(self $a, self $b): self
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    public function __toString(): string
    {
        return number_format($this->kwh, 4).' kWh';
    }
}
