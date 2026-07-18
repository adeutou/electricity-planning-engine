<?php

declare(strict_types=1);

namespace App\Domain\Arbitrage;

use App\Domain\Asset\Battery;
use App\Domain\Asset\ConsumptionProfile;
use App\Domain\Asset\PhotovoltaicSystem;
use App\Domain\Contract\EnergyContract;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Power;
use App\Domain\Shared\ValueObject\TimeHorizon;

/**
 * Regroupe toutes les entrées dont un ArbitrageEngineInterface a besoin pour
 * produire un ArbitragePlan. Un simple bundle immuable : construire ce VO
 * est la responsabilité du use case applicatif (SimulateArbitrageUseCase),
 * qui va chercher/assemble contrat, prix, profils et actifs à partir de la
 * requête API — les moteurs, eux, ne connaissent que ce contexte.
 */
final class ArbitrageContext
{
    public function __construct(
        private readonly EnergyContract $contract,
        private readonly TimeHorizon $horizon,
        private readonly PriceSeries $prices,
        private readonly ConsumptionProfile $consumption,
        private readonly PhotovoltaicSystem $pv,
        private readonly Battery $battery,
        private readonly Power $maxExportPower,
    ) {}

    public function contract(): EnergyContract
    {
        return $this->contract;
    }

    public function horizon(): TimeHorizon
    {
        return $this->horizon;
    }

    public function prices(): PriceSeries
    {
        return $this->prices;
    }

    public function consumption(): ConsumptionProfile
    {
        return $this->consumption;
    }

    public function pv(): PhotovoltaicSystem
    {
        return $this->pv;
    }

    public function battery(): Battery
    {
        return $this->battery;
    }

    /**
     * Puissance maximale de réinjection vers le réseau (contrainte
     * contractuelle/réglementaire, ex. 3 kW).
     */
    public function maxExportPower(): Power
    {
        return $this->maxExportPower;
    }
}
