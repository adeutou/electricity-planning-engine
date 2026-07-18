<?php

declare(strict_types=1);

namespace App\Domain\Arbitrage;

use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * Résultat complet d'une simulation : la liste ordonnée des décisions
 * heure par heure produites par un ArbitrageEngineInterface, plus les
 * agrégats qu'on veut typiquement afficher (coût total, énergie totale
 * importée/exportée...).
 *
 * @implements IteratorAggregate<int, HourlyDecision>
 */
final class ArbitragePlan implements Countable, IteratorAggregate
{
    /** @var list<HourlyDecision> */
    private readonly array $hours;

    /**
     * @param  list<HourlyDecision>  $hours
     */
    public function __construct(
        private readonly string $zone,
        private readonly string $mode,
        array $hours,
    ) {
        $this->hours = array_values($hours);
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return list<HourlyDecision>
     */
    public function hours(): array
    {
        return $this->hours;
    }

    public function totalCost(): Money
    {
        return array_reduce(
            $this->hours,
            fn (Money $carry, HourlyDecision $hour) => $carry->add($hour->cost()),
            Money::zero()
        );
    }

    public function totalConsumption(): Energy
    {
        return $this->sumEnergy(fn (HourlyDecision $hour) => $hour->consumption());
    }

    public function totalPvProduction(): Energy
    {
        return $this->sumEnergy(fn (HourlyDecision $hour) => $hour->pvProduction());
    }

    public function totalExport(): Energy
    {
        return $this->sumEnergy(fn (HourlyDecision $hour) => $hour->exportToGrid());
    }

    public function count(): int
    {
        return count($this->hours);
    }

    /**
     * @return ArrayIterator<int, HourlyDecision>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->hours);
    }

    /**
     * @param  callable(HourlyDecision): Energy  $extractor
     */
    private function sumEnergy(callable $extractor): Energy
    {
        return array_reduce(
            $this->hours,
            fn (Energy $carry, HourlyDecision $hour) => $carry->add($extractor($hour)),
            Energy::zero()
        );
    }
}
