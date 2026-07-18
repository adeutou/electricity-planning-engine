<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\StateOfCharge;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Percentage;
use App\Domain\Shared\ValueObject\Power;

/**
 * Batterie domestique. Entité immuable : charge()/discharge() retournent une
 * nouvelle instance reflétant le nouvel état de charge, plutôt que de muter
 * l'objet — cohérent avec le reste du domaine et nécessaire pour que le
 * moteur d'arbitrage puisse explorer/comparer plusieurs trajectoires (V2)
 * sans effets de bord.
 *
 * Modélisation du rendement : le rendement aller-retour (round-trip
 * efficiency, ex. 90%) est réparti à parts égales entre charge et décharge
 * via sa racine carrée (`sqrt(0.90) ≈ 0.949` de chaque côté). C'est une
 * simplification standard en l'absence de données constructeur séparées
 * pour les deux rendements.
 */
final class Battery
{
    private function __construct(
        private readonly Energy $capacity,
        private readonly Power $maxChargePower,
        private readonly Power $maxDischargePower,
        private readonly float $roundTripEfficiency,
        private readonly Percentage $socMin,
        private readonly Percentage $socMax,
        private readonly StateOfCharge $soc,
    ) {
        if ($this->roundTripEfficiency <= 0.0 || $this->roundTripEfficiency > 1.0) {
            throw DomainException::because(
                "Round-trip efficiency must be in (0, 1], got {$this->roundTripEfficiency}."
            );
        }

        if ($this->socMin->value() >= $this->socMax->value()) {
            throw DomainException::because('socMin must be strictly lower than socMax.');
        }

        if (! $this->soc->isAtOrAbove($this->socMin) || ! $this->soc->isAtOrBelow($this->socMax)) {
            throw DomainException::because(
                "Initial state of charge ({$this->soc->percentage()}) must be within [{$this->socMin}, {$this->socMax}]."
            );
        }
    }

    public static function create(
        Energy $capacity,
        Power $maxChargePower,
        Power $maxDischargePower,
        float $roundTripEfficiency,
        Percentage $socMin,
        Percentage $socMax,
        Percentage $initialSoc,
    ): self {
        return new self(
            $capacity,
            $maxChargePower,
            $maxDischargePower,
            $roundTripEfficiency,
            $socMin,
            $socMax,
            StateOfCharge::fromPercentage($initialSoc, $capacity),
        );
    }

    public function capacity(): Energy
    {
        return $this->capacity;
    }

    public function maxChargePower(): Power
    {
        return $this->maxChargePower;
    }

    public function maxDischargePower(): Power
    {
        return $this->maxDischargePower;
    }

    public function socMin(): Percentage
    {
        return $this->socMin;
    }

    public function socMax(): Percentage
    {
        return $this->socMax;
    }

    public function stateOfCharge(): StateOfCharge
    {
        return $this->soc;
    }

    private function chargeEfficiency(): float
    {
        return sqrt($this->roundTripEfficiency);
    }

    private function dischargeEfficiency(): float
    {
        return sqrt($this->roundTripEfficiency);
    }

    /**
     * Marge de stockage disponible avant d'atteindre socMax (en kWh stockés).
     */
    public function chargeHeadroom(): Energy
    {
        $maxLevelKwh = $this->capacity->kwh() * $this->socMax->toFraction();
        $headroomKwh = $maxLevelKwh - $this->soc->level()->kwh();

        return Energy::fromKwh(max(0.0, $headroomKwh));
    }

    /**
     * Marge de déstockage disponible avant d'atteindre socMin (en kWh stockés).
     */
    public function dischargeHeadroom(): Energy
    {
        $minLevelKwh = $this->capacity->kwh() * $this->socMin->toFraction();
        $headroomKwh = $this->soc->level()->kwh() - $minLevelKwh;

        return Energy::fromKwh(max(0.0, $headroomKwh));
    }

    /**
     * Énergie maximale, côté réseau/PV (avant pertes de charge), que la
     * batterie peut absorber pendant $hours heures.
     */
    public function maxChargeableEnergy(float $hours = 1.0): Energy
    {
        $powerLimit = $this->maxChargePower->toEnergy($hours);
        $headroom = $this->chargeHeadroom();

        if ($headroom->isZero()) {
            return Energy::zero();
        }

        $headroomAsInputEnergy = Energy::fromKwh($headroom->kwh() / $this->chargeEfficiency());

        return Energy::min($powerLimit, $headroomAsInputEnergy);
    }

    /**
     * Énergie maximale, utile au compteur (après pertes de décharge), que la
     * batterie peut restituer pendant $hours heures.
     */
    public function maxDischargeableEnergy(float $hours = 1.0): Energy
    {
        $powerLimit = $this->maxDischargePower->toEnergy($hours);
        $headroomAsOutputEnergy = $this->dischargeHeadroom()->multiply($this->dischargeEfficiency());

        return Energy::min($powerLimit, $headroomAsOutputEnergy);
    }

    /**
     * @throws DomainException si $gridEnergyIn dépasse maxChargeableEnergy().
     */
    public function charge(Energy $gridEnergyIn, float $hours = 1.0): self
    {
        $limit = $this->maxChargeableEnergy($hours);

        if ($gridEnergyIn->isGreaterThan($limit)) {
            throw DomainException::because(
                "Cannot charge {$gridEnergyIn}: exceeds max chargeable energy of {$limit} for {$hours}h."
            );
        }

        $storedIncrease = $gridEnergyIn->multiply($this->chargeEfficiency());

        return $this->withSoc($this->soc->withLevel($this->soc->level()->add($storedIncrease)));
    }

    /**
     * @throws DomainException si $outputEnergy dépasse maxDischargeableEnergy().
     */
    public function discharge(Energy $outputEnergy, float $hours = 1.0): self
    {
        $limit = $this->maxDischargeableEnergy($hours);

        if ($outputEnergy->isGreaterThan($limit)) {
            throw DomainException::because(
                "Cannot discharge {$outputEnergy}: exceeds max dischargeable energy of {$limit} for {$hours}h."
            );
        }

        $storedDecrease = $outputEnergy->multiply(1 / $this->dischargeEfficiency());

        return $this->withSoc($this->soc->withLevel($this->soc->level()->subtract($storedDecrease)));
    }

    private function withSoc(StateOfCharge $soc): self
    {
        return new self(
            $this->capacity,
            $this->maxChargePower,
            $this->maxDischargePower,
            $this->roundTripEfficiency,
            $this->socMin,
            $this->socMax,
            $soc,
        );
    }
}
