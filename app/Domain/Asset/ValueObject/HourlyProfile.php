<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;

/**
 * Série d'énergies indexée par heure (0-based) sur un horizon donné :
 * profil de consommation ou de production PV explicite, fourni en entrée
 * d'une simulation plutôt que dérivé d'un modèle synthétique.
 */
final class HourlyProfile
{
    /** @var list<Energy> */
    private readonly array $values;

    /**
     * @param  list<Energy>  $values
     */
    private function __construct(array $values)
    {
        $this->values = array_values($values);
    }

    /**
     * @param  list<float>  $kwhValues
     */
    public static function fromKwhValues(array $kwhValues): self
    {
        return new self(array_map(
            static fn (float $kwh) => Energy::fromKwh($kwh),
            array_values($kwhValues)
        ));
    }

    /**
     * @param  list<Energy>  $values
     */
    public static function fromEnergies(array $values): self
    {
        return new self($values);
    }

    public function at(int $hourIndex): Energy
    {
        if (! array_key_exists($hourIndex, $this->values)) {
            throw DomainException::because("HourlyProfile has no value at hour index {$hourIndex}.");
        }

        return $this->values[$hourIndex];
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return list<float>
     */
    public function toKwhArray(): array
    {
        return array_map(static fn (Energy $energy) => $energy->kwh(), $this->values);
    }
}
