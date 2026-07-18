<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\DTO;

use App\Domain\Contract\EnergyContract;

/**
 * Résultat de la persistance d'un EnergyContractInput : l'id relationnel
 * (pour la FK `simulation_plans.energy_contract_id`), l'entité du domaine
 * prête à l'emploi (déjà hydratée avec sa PricingStrategy), et un
 * instantané JSON du contrat (pour `simulation_plans.contract_snapshot`).
 */
final class EnergyContractRecord
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly int $id,
        public readonly EnergyContract $contract,
        public readonly array $snapshot,
    ) {}
}
