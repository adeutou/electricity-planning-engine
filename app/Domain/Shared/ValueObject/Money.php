<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;

/**
 * Montant monétaire, signé (un coût horaire peut être négatif : c'est un
 * revenu net, typique d'une heure d'export pendant un prix spot négatif).
 *
 * Sert à la fois pour des tarifs unitaires (€/kWh) et des montants absolus
 * (coût d'une heure) — le domaine ne distingue pas les deux types au niveau
 * du VO, c'est le contexte d'usage (nom du champ, méthode appelante) qui
 * porte la sémantique.
 */
final class Money
{
    private const EPSILON = 1e-9;

    private readonly float $amount;

    private readonly string $currency;

    private function __construct(float $amount, string $currency)
    {
        $this->amount = round($amount, 9);
        $this->currency = strtoupper($currency);
    }

    public static function of(float $amount, string $currency = 'EUR'): self
    {
        return new self($amount, $currency);
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0.0, $currency);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(float $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Traite $this comme un tarif en €/kWh et le multiplie par une quantité
     * d'énergie pour obtenir un montant absolu.
     */
    public function timesEnergy(Energy $energy): self
    {
        return new self($this->amount * $energy->kwh(), $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < -self::EPSILON;
    }

    public function isPositive(): bool
    {
        return $this->amount > self::EPSILON;
    }

    public function isZero(): bool
    {
        return abs($this->amount) < self::EPSILON;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && abs($this->amount - $other->amount) < self::EPSILON;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return ($this->amount - $other->amount) > self::EPSILON;
    }

    public function isLessThan(self $other): bool
    {
        return $other->isGreaterThan($this);
    }

    public static function min(self $a, self $b): self
    {
        return $a->isGreaterThan($b) ? $b : $a;
    }

    public static function max(self $a, self $b): self
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw DomainException::because(
                "Cannot combine amounts in different currencies: {$this->currency} vs {$other->currency}."
            );
        }
    }

    public function __toString(): string
    {
        return number_format($this->amount, 4).' '.$this->currency;
    }
}
