<?php

declare(strict_types=1);

namespace App\Domain\Arbitrage;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;

/**
 * Décision d'arbitrage pour une heure : d'où vient l'énergie consommée, où
 * va la production PV, et le coût résultant. Reflète exactement les colonnes
 * de `plan_hours` (voir migration correspondante) — c'est la même grandeur,
 * simplement encapsulée avec ses invariants côté domaine plutôt que côté
 * base de données.
 *
 * Seul l'équilibre de la consommation est vérifié ici (elle doit être
 * intégralement couverte par réseau + PV + batterie) : c'est un invariant
 * physique non négociable. L'équilibre du côté production PV (PV = autoconso
 * + export + charge batterie) dépend en revanche de la stratégie de
 * l'engine (une partie du surplus PV peut être écrêtée) et n'est donc pas
 * imposé au niveau du value object, mais vérifié par les tests d'engine.
 */
final class HourlyDecision
{
    public function __construct(
        private readonly int $hourIndex,
        private readonly DateTimeImmutable $startsAt,
        private readonly Money $pricePerKwh,
        private readonly Energy $consumption,
        private readonly Energy $pvProduction,
        private readonly Energy $consumptionFromGrid,
        private readonly Energy $consumptionFromPv,
        private readonly Energy $consumptionFromBattery,
        private readonly Energy $batteryCharge,
        private readonly Energy $batteryDischarge,
        private readonly Energy $exportToGrid,
        private readonly Energy $socEndOfHour,
        private readonly Money $cost,
    ) {
        $covered = $consumptionFromGrid->add($consumptionFromPv)->add($consumptionFromBattery);

        if (! $covered->equals($consumption)) {
            throw DomainException::because(
                "Hour {$hourIndex}: consumption ({$consumption}) is not fully covered by ".
                "grid + PV + battery ({$covered})."
            );
        }
    }

    public function hourIndex(): int
    {
        return $this->hourIndex;
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function pricePerKwh(): Money
    {
        return $this->pricePerKwh;
    }

    public function consumption(): Energy
    {
        return $this->consumption;
    }

    public function pvProduction(): Energy
    {
        return $this->pvProduction;
    }

    public function consumptionFromGrid(): Energy
    {
        return $this->consumptionFromGrid;
    }

    public function consumptionFromPv(): Energy
    {
        return $this->consumptionFromPv;
    }

    public function consumptionFromBattery(): Energy
    {
        return $this->consumptionFromBattery;
    }

    public function batteryCharge(): Energy
    {
        return $this->batteryCharge;
    }

    public function batteryDischarge(): Energy
    {
        return $this->batteryDischarge;
    }

    public function exportToGrid(): Energy
    {
        return $this->exportToGrid;
    }

    public function socEndOfHour(): Energy
    {
        return $this->socEndOfHour;
    }

    public function cost(): Money
    {
        return $this->cost;
    }
}
