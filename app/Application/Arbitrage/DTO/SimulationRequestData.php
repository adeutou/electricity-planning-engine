<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\DTO;

use DateTimeImmutable;

/**
 * Entrée de SimulateArbitrageUseCase, déjà "parsée" en types PHP primitifs
 * mais pas encore validée métier (c'est le rôle du use case et des value
 * objects du domaine qu'il construit). La validation *syntaxique* du JSON
 * brut (types, présence des champs requis) est la responsabilité du
 * SimulateRequest (Form Request Laravel) qui construit ce DTO — ce DTO est
 * donc le contrat entre Http et Application, indépendant de la requête HTTP
 * elle-même.
 *
 * $battery / $pv / $consumption sont nullables (et chaque clé de leur
 * tableau l'est aussi individuellement) : toute valeur absente retombe sur
 * config('energy'), pour permettre un appel minimal ne précisant que ce qui
 * diffère des valeurs par défaut d'un foyer type.
 */
final class SimulationRequestData
{
    /**
     * @param  array{name?:string,country_code:string,zone:string,contract_type:string,pricing_config:array<string,mixed>,currency?:string,subscribed_power_kva?:float,timezone?:string}  $contract
     * @param  array{capacity_kwh?:float,max_charge_power_kw?:float,max_discharge_power_kw?:float,round_trip_efficiency?:float,soc_min_percent?:float,soc_max_percent?:float,initial_soc_percent?:float}|null  $battery
     * @param  array{peak_power_kwc?:float,hourly_profile_kwh?:list<float>}|null  $pv
     * @param  array{daily_baseline_kwh?:float,hourly_profile_kwh?:list<float>}|null  $consumption
     */
    public function __construct(
        public readonly array $contract,
        public readonly DateTimeImmutable $horizonStart,
        public readonly DateTimeImmutable $horizonEnd,
        public readonly string $timezone,
        public readonly string $mode,
        public readonly ?string $priceProvider = null,
        public readonly ?array $battery = null,
        public readonly ?array $pv = null,
        public readonly ?array $consumption = null,
        public readonly ?float $maxExportPowerKw = null,
    ) {}
}
