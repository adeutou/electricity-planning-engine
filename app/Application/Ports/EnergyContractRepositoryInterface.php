<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Arbitrage\DTO\EnergyContractInput;
use App\Application\Arbitrage\DTO\EnergyContractRecord;

/**
 * Port de persistance pour les contrats énergétiques. POST /api/simulate
 * reçoit une définition de contrat "inline" (pas de référence à un contrat
 * pré-existant) : chaque simulation crée son propre enregistrement
 * `energy_contracts`, ce qui garantit à la fois la contrainte de clé
 * étrangère de `simulation_plans` et un instantané fidèle sans logique de
 * déduplication superflue pour ce projet de démonstration.
 */
interface EnergyContractRepositoryInterface
{
    public function create(EnergyContractInput $input): EnergyContractRecord;
}
