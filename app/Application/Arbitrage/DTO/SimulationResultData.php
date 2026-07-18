<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\DTO;

use App\Domain\Arbitrage\ArbitragePlan;

/**
 * Sortie de SimulateArbitrageUseCase : l'identifiant sous lequel le plan a
 * été persisté (réutilisable via GET /api/plans/{id}) et le plan lui-même,
 * prêt à être formaté par une Http Resource.
 */
final class SimulationResultData
{
    public function __construct(
        public readonly string $id,
        public readonly ArbitragePlan $plan,
    ) {}
}
