<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;

/**
 * Série chronologique de prix pour une zone donnée. Toutes les lectures
 * (`priceAt`, `average`, `min`, `max`...) exposent des prix en €/kWh : c'est
 * l'unité de travail des moteurs d'arbitrage et des stratégies tarifaires,
 * qui n'ont pas à connaître la convention €/MWh des marchés de gros.
 *
 * @implements IteratorAggregate<int, PricePoint>
 */
final class PriceSeries implements Countable, IteratorAggregate
{
    /** @var array<string, PricePoint> indexé par timestamp ISO-8601 pour un lookup O(1) */
    private readonly array $byTimestamp;

    /** @var list<PricePoint> trié chronologiquement */
    private readonly array $points;

    private readonly string $zone;

    /**
     * @param  list<PricePoint>  $points
     */
    public function __construct(string $zone, array $points)
    {
        $this->zone = $zone;

        usort($points, fn (PricePoint $a, PricePoint $b) => $a->timestamp() <=> $b->timestamp());

        $indexed = [];

        foreach ($points as $point) {
            if ($point->zone() !== $zone) {
                throw DomainException::because(
                    "PriceSeries for zone '{$zone}' received a point for zone '{$point->zone()}'."
                );
            }

            $indexed[$this->key($point->timestamp())] = $point;
        }

        $this->points = array_values($indexed);
        $this->byTimestamp = $indexed;
    }

    public static function empty(string $zone): self
    {
        return new self($zone, []);
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function count(): int
    {
        return count($this->points);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function has(DateTimeImmutable $hour): bool
    {
        return isset($this->byTimestamp[$this->key($hour)]);
    }

    public function priceAt(DateTimeImmutable $hour): Money
    {
        $point = $this->byTimestamp[$this->key($hour)] ?? null;

        if ($point === null) {
            throw DomainException::because(
                "No price available for zone '{$this->zone}' at ".$hour->format(DATE_ATOM).'.'
            );
        }

        return $point->pricePerKwh();
    }

    public function average(): Money
    {
        if ($this->isEmpty()) {
            throw DomainException::because('Cannot average an empty PriceSeries.');
        }

        $sum = array_reduce(
            $this->points,
            fn (Money $carry, PricePoint $point) => $carry->add($point->pricePerKwh()),
            Money::zero()
        );

        return $sum->multiply(1 / $this->count());
    }

    /**
     * Moyenne des prix sur la fenêtre [from, to) — utilisée par les moteurs
     * d'arbitrage pour comparer le prix courant à la tendance des N heures
     * passées (lookbehind) ou futures (lookahead).
     */
    public function averageBetween(DateTimeImmutable $from, DateTimeImmutable $to): Money
    {
        $window = $this->slice($from, $to);

        return $window->average();
    }

    public function min(): Money
    {
        return $this->extremum(fn (Money $a, Money $b) => $a->isLessThan($b));
    }

    public function max(): Money
    {
        return $this->extremum(fn (Money $a, Money $b) => $a->isGreaterThan($b));
    }

    public function slice(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        $filtered = array_values(array_filter(
            $this->points,
            fn (PricePoint $point) => $point->timestamp() >= $from && $point->timestamp() < $to
        ));

        return new self($this->zone, $filtered);
    }

    /**
     * @return ArrayIterator<int, PricePoint>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->points);
    }

    private function extremum(callable $isBetter): Money
    {
        if ($this->isEmpty()) {
            throw DomainException::because('Cannot compute an extremum on an empty PriceSeries.');
        }

        $best = $this->points[0]->pricePerKwh();

        foreach ($this->points as $point) {
            $candidate = $point->pricePerKwh();

            if ($isBetter($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function key(DateTimeImmutable $hour): string
    {
        return $hour->format(DATE_ATOM);
    }
}
