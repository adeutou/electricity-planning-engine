<?php

declare(strict_types=1);

namespace App\Domain\Contract\ValueObject;

use App\Domain\Contract\Enum\SpecialDayColor;
use DateTimeImmutable;

/**
 * Assigne une couleur de jour (cf. SpecialDayColor) à une date calendaire
 * précise. Une TempoPricingStrategy est construite à partir d'une liste de
 * ces règles ; tout jour non couvert retombe sur une couleur par défaut
 * (Bleu), fidèle au fonctionnement réel du calendrier Tempo où les jours
 * rouges/blancs sont l'exception (~44 jours/an) et le bleu la norme.
 */
final class SpecialDayRule
{
    private readonly DateTimeImmutable $date;

    public function __construct(
        DateTimeImmutable $date,
        private readonly SpecialDayColor $color,
    ) {
        $this->date = $date->setTime(0, 0);
    }

    public function matches(DateTimeImmutable $moment): bool
    {
        return $this->date->format('Y-m-d') === $moment->format('Y-m-d');
    }

    public function color(): SpecialDayColor
    {
        return $this->color;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }
}
