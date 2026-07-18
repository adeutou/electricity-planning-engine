<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enum;

/**
 * Type de plage horaire tarifaire. "Peak"/"OffPeak" plutôt que des noms
 * français ("HP"/"HC") pour rester générique : le même enum sert à modéliser
 * les heures pleines/creuses françaises ou un équivalent belge/espagnol.
 */
enum TimeSlotType: string
{
    case Peak = 'peak';
    case OffPeak = 'off_peak';
}
