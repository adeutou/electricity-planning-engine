<?php

declare(strict_types=1);

namespace App\Domain\Contract\ValueObject;

use App\Domain\Contract\Enum\TimeSlotType;
use App\Domain\Shared\ValueObject\Money;

/**
 * Association d'un type de plage horaire (peak/off-peak) à un tarif
 * unitaire en €/kWh.
 */
final class TariffRate
{
    public function __construct(
        private readonly TimeSlotType $slotType,
        private readonly Money $pricePerKwh,
    ) {}

    public function slotType(): TimeSlotType
    {
        return $this->slotType;
    }

    public function pricePerKwh(): Money
    {
        return $this->pricePerKwh;
    }
}
