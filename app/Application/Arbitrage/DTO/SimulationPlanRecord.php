<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\DTO;

use App\Domain\Arbitrage\ArbitragePlan;
use DateTimeImmutable;

/**
 * Enveloppe un ArbitragePlan avec tout ce qu'il faut pour le persister et le
 * retrouver tel quel plus tard (table `simulation_plans` + `plan_hours`).
 *
 * Les *_snapshot/*_config sont des tableaux bruts (pas des value objects du
 * domaine) : ce sont des copies figées de la configuration effectivement
 * utilisée au moment du calcul (contrat, batterie, PV, consommation), pour
 * que GET /api/plans/{id} reste reproductible même si le contrat source
 * change ou est supprimé entre-temps (cf. le commentaire sur
 * `contract_snapshot` dans la migration `simulation_plans`).
 */
final class SimulationPlanRecord
{
    /**
     * @param  array<string, mixed>  $contractSnapshot
     * @param  array<string, mixed>  $batteryConfig
     * @param  array<string, mixed>  $pvConfig
     * @param  array<string, mixed>  $consumptionConfig
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ArbitragePlan $plan,
        public readonly ?int $energyContractId,
        public readonly string $priceProvider,
        public readonly DateTimeImmutable $horizonStart,
        public readonly DateTimeImmutable $horizonEnd,
        public readonly string $timezone,
        public readonly array $contractSnapshot,
        public readonly array $batteryConfig,
        public readonly array $pvConfig,
        public readonly array $consumptionConfig,
        public readonly array $metadata = [],
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {}
}
