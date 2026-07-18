<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\DTO;

/**
 * Définition brute d'un contrat telle que reçue dans le corps de
 * POST /api/simulate. `contractType` reste une string non validée à ce
 * stade (la validation — ContractType::from() + PricingStrategyFactory —
 * se fait au moment de l'hydratation en entité du domaine, pas ici : ce DTO
 * ne fait que transporter la donnée depuis le use case vers le repository).
 */
final class EnergyContractInput
{
    /**
     * @param  array<string, mixed>  $pricingConfig
     */
    public function __construct(
        public readonly string $name,
        public readonly string $countryCode,
        public readonly string $zone,
        public readonly string $contractType,
        public readonly array $pricingConfig,
        public readonly string $currency = 'EUR',
        public readonly ?float $subscribedPowerKva = null,
        public readonly string $timezone = 'Europe/Paris',
    ) {}
}
