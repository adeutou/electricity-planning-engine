<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\Engine;

use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Arbitrage\ArbitrageEngineInterface;
use App\Domain\Arbitrage\ArbitragePlan;
use App\Domain\Arbitrage\HourlyDecision;
use App\Domain\Asset\Battery;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;

/**
 * Moteur d'arbitrage V1 : règles "greedy" heure par heure, volontairement
 * simples et lisibles plutôt qu'optimales au sens mathématique. L'objectif
 * n'est pas de trouver le plan à coût minimal sur tout l'horizon (c'est le
 * rôle de la V2, cf. AdvancedArbitrageEngine), mais de produire un
 * comportement cohérent avec l'intuition métier et facile à tester /
 * expliquer à un utilisateur final :
 *
 *  1. La production PV couvre la consommation en priorité (autoconsommation).
 *  2. S'il reste un surplus PV :
 *     - il charge la batterie si elle n'est pas pleine ET que le prix
 *       courant est plus avantageux que le prix moyen des N heures futures
 *       (lookahead) — autrement dit : "il vaut mieux stocker maintenant que
 *       vendre maintenant, si les heures à venir coûteront plus cher" ;
 *     - le reste est exporté vers le réseau (dans la limite de la puissance
 *       d'export max du contrat).
 *  3. S'il reste un déficit (conso > PV) :
 *     - la batterie se décharge si le prix courant est plus élevé que le
 *       prix moyen des N heures passées (lookbehind) — "il vaut mieux
 *       puiser dans le stock maintenant que ça coûte cher, plutôt que
 *       d'acheter au réseau" ;
 *     - le reste est importé du réseau (le réseau couvre toujours le solde,
 *       il n'y a pas de délestage possible en V1).
 *
 * Le prix comparé est celui du contrat (EnergyContract::priceForHour), pas
 * directement le prix de marché : c'est ce que paie réellement l'utilisateur
 * qui doit guider la décision, quel que soit le type de contrat (fixe,
 * HP/HC, Tempo ou spot). C'est cette indirection via PricingStrategyInterface
 * qui permet au moteur de rester identique pour tous les types de contrat.
 */
final class SimpleArbitrageEngine implements ArbitrageEngineInterface
{
    public const MODE = 'simple';

    public function __construct(
        private readonly int $lookaheadHours = 6,
        private readonly int $lookbehindHours = 6,
    ) {}

    public function plan(ArbitrageContext $context): ArbitragePlan
    {
        $horizon = $context->horizon();
        $pv = $context->pv();
        $consumption = $context->consumption();
        $battery = $context->battery();
        $maxExportEnergy = $context->maxExportPower()->toEnergy(1.0);

        $pricesByHour = $this->resolveContractPrices($context);

        $hours = [];

        foreach ($horizon->iterateHours() as $hourIndex => $hourStart) {
            $priceNow = $pricesByHour[$hourIndex];
            $pvProduction = $pv->productionAt($hourStart, $hourIndex);
            $demand = $consumption->consumptionAt($hourStart, $hourIndex);

            $consumptionFromPv = Energy::min($pvProduction, $demand);
            $surplus = $pvProduction->subtract($consumptionFromPv);
            $deficit = $demand->subtract($consumptionFromPv);

            $batteryCharge = Energy::zero();
            $batteryDischarge = Energy::zero();
            $consumptionFromBattery = Energy::zero();
            $exportToGrid = Energy::zero();

            if (! $surplus->isZero()) {
                [$battery, $batteryCharge, $exportToGrid] = $this->handleSurplus(
                    $battery, $surplus, $maxExportEnergy, $priceNow, $pricesByHour, $hourIndex,
                );
            } elseif (! $deficit->isZero()) {
                [$battery, $batteryDischarge] = $this->handleDeficit(
                    $battery, $deficit, $priceNow, $pricesByHour, $hourIndex,
                );
                $consumptionFromBattery = $batteryDischarge;
            }

            $consumptionFromGrid = $demand
                ->subtract($consumptionFromPv)
                ->subtract($consumptionFromBattery);

            $cost = $priceNow->timesEnergy($consumptionFromGrid)
                ->subtract($priceNow->timesEnergy($exportToGrid));

            $hours[] = new HourlyDecision(
                hourIndex: $hourIndex,
                startsAt: $hourStart,
                pricePerKwh: $priceNow,
                consumption: $demand,
                pvProduction: $pvProduction,
                consumptionFromGrid: $consumptionFromGrid,
                consumptionFromPv: $consumptionFromPv,
                consumptionFromBattery: $consumptionFromBattery,
                batteryCharge: $batteryCharge,
                batteryDischarge: $batteryDischarge,
                exportToGrid: $exportToGrid,
                socEndOfHour: $battery->stateOfCharge()->level(),
                cost: $cost,
            );
        }

        return new ArbitragePlan($context->contract()->zone(), self::MODE, $hours);
    }

    /**
     * @param  array<int, Money>  $pricesByHour
     * @return array{0: Battery, 1: Energy, 2: Energy} [battery, batteryCharge, exportToGrid]
     */
    private function handleSurplus(
        Battery $battery,
        Energy $surplus,
        Energy $maxExportEnergy,
        Money $priceNow,
        array $pricesByHour,
        int $hourIndex,
    ): array {
        $batteryCharge = Energy::zero();

        if (! $battery->chargeHeadroom()->isZero() && $this->shouldChargeFromSurplus($priceNow, $pricesByHour, $hourIndex)) {
            $batteryCharge = Energy::min($surplus, $battery->maxChargeableEnergy());
            $battery = $battery->charge($batteryCharge);
        }

        // Le surplus non stocké est exporté, dans la limite de la puissance
        // d'export contractuelle. Un éventuel reliquat au-delà de cette
        // limite est écrêté (simplification V1 : un onduleur réel limiterait
        // la production plutôt que de la perdre "virtuellement", mais ce
        // détail n'a pas d'incidence sur le coût calculé ici).
        $exportToGrid = Energy::min($surplus->subtract($batteryCharge), $maxExportEnergy);

        return [$battery, $batteryCharge, $exportToGrid];
    }

    /**
     * @param  array<int, Money>  $pricesByHour
     * @return array{0: Battery, 1: Energy} [battery, batteryDischarge]
     */
    private function handleDeficit(
        Battery $battery,
        Energy $deficit,
        Money $priceNow,
        array $pricesByHour,
        int $hourIndex,
    ): array {
        $batteryDischarge = Energy::zero();

        if (! $battery->dischargeHeadroom()->isZero() && $this->shouldDischargeForDeficit($priceNow, $pricesByHour, $hourIndex)) {
            $batteryDischarge = Energy::min($deficit, $battery->maxDischargeableEnergy());
            $battery = $battery->discharge($batteryDischarge);
        }

        return [$battery, $batteryDischarge];
    }

    /**
     * @param  array<int, Money>  $pricesByHour
     */
    private function shouldChargeFromSurplus(Money $priceNow, array $pricesByHour, int $hourIndex): bool
    {
        $futurePrices = $this->priceWindow($pricesByHour, $hourIndex + 1, $hourIndex + 1 + $this->lookaheadHours);

        if ($futurePrices === []) {
            // Fin d'horizon : aucune heure future à comparer. Par prudence,
            // on n'immobilise pas de capacité batterie sans visibilité —
            // le surplus est exporté plutôt que stocké.
            return false;
        }

        return $priceNow->isLessThan($this->average($futurePrices));
    }

    /**
     * @param  array<int, Money>  $pricesByHour
     */
    private function shouldDischargeForDeficit(Money $priceNow, array $pricesByHour, int $hourIndex): bool
    {
        $pastPrices = $this->priceWindow($pricesByHour, $hourIndex - $this->lookbehindHours, $hourIndex);

        if ($pastPrices === []) {
            // Début d'horizon : aucun historique de prix. Par prudence, on
            // préserve la batterie plutôt que de la solliciter à l'aveugle.
            return false;
        }

        return $priceNow->isGreaterThan($this->average($pastPrices));
    }

    /**
     * @param  array<int, Money>  $pricesByHour
     * @return list<Money>
     */
    private function priceWindow(array $pricesByHour, int $fromIndexInclusive, int $toIndexExclusive): array
    {
        $window = [];

        for ($i = max(0, $fromIndexInclusive); $i < $toIndexExclusive; $i++) {
            if (isset($pricesByHour[$i])) {
                $window[] = $pricesByHour[$i];
            }
        }

        return $window;
    }

    /**
     * @param  list<Money>  $prices
     */
    private function average(array $prices): Money
    {
        $sum = array_reduce(
            $prices,
            fn (Money $carry, Money $price) => $carry->add($price),
            Money::zero($prices[0]->currency())
        );

        return $sum->multiply(1 / count($prices));
    }

    /**
     * @return array<int, Money> Prix contractuel (€/kWh) pour chaque heure de l'horizon.
     */
    private function resolveContractPrices(ArbitrageContext $context): array
    {
        $prices = [];

        foreach ($context->horizon()->iterateHours() as $hourIndex => $hourStart) {
            $prices[$hourIndex] = $context->contract()->priceForHour($hourStart, $context->prices());
        }

        return $prices;
    }
}
