<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enum;

/**
 * Couleur de jour au sens des contrats type EDF Tempo : chaque jour de
 * l'année est classé Bleu/Blanc/Rouge par le fournisseur (calendrier connu
 * la veille pour le lendemain), avec un tarif HP/HC propre à chaque couleur.
 * Les jours rouges (les plus chers, ~22/an en France) matérialisent aussi la
 * notion de "jour d'effacement" évoquée dans la fiche de poste.
 */
enum SpecialDayColor: string
{
    case Blue = 'blue';
    case White = 'white';
    case Red = 'red';
}
