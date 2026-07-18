<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enum;

/**
 * Grande famille tarifaire d'un contrat énergétique. Chaque cas correspond
 * à une implémentation de PricingStrategyInterface (cf.
 * App\Domain\Contract\PricingStrategy\PricingStrategyFactory), mais l'enum
 * lui-même reste ignorant de cette correspondance pour ne pas coupler la
 * définition du type à sa résolution.
 */
enum ContractType: string
{
    /** Prix unique quelle que soit l'heure. */
    case Fixed = 'fixed';

    /** Heures pleines / heures creuses (ou équivalent hors France). */
    case PeakOffPeak = 'peak_off_peak';

    /** Type EDF Tempo/EJP : tarif dépendant d'un calendrier de jours colorés. */
    case Tempo = 'tempo';

    /** Tarif indexé sur le marché spot (day-ahead) + marge fournisseur. */
    case DynamicSpot = 'dynamic_spot';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed rate',
            self::PeakOffPeak => 'Peak / off-peak hours',
            self::Tempo => 'Tempo (colored days)',
            self::DynamicSpot => 'Dynamic spot-indexed',
        };
    }
}
