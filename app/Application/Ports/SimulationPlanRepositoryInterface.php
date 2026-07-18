<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;

/**
 * Port de persistance pour les plans de simulation. Vit dans Application
 * (et non Domain) car SimulationPlanRecord porte des préoccupations de
 * traçabilité/historisation (snapshots, provider utilisé, horodatage de
 * création) qui relèvent de l'orchestration d'un cas d'usage, pas de règles
 * métier au sens strict.
 */
interface SimulationPlanRepositoryInterface
{
    /**
     * @return string L'identifiant (ULID) du plan persisté.
     */
    public function save(SimulationPlanRecord $record): string;

    public function findById(string $id): ?SimulationPlanRecord;
}
