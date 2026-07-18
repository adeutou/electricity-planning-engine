<?php

declare(strict_types=1);

namespace App\Application\Arbitrage\Engine;

use App\Application\Arbitrage\ContractPriceResolver;
use App\Domain\Arbitrage\ArbitrageContext;
use App\Domain\Arbitrage\ArbitrageEngineInterface;
use App\Domain\Arbitrage\ArbitragePlan;
use App\Domain\Arbitrage\HourlyDecision;
use App\Domain\Asset\Battery;
use App\Domain\Shared\ValueObject\Energy;
use App\Domain\Shared\ValueObject\Money;

/**
 * Moteur d'arbitrage V2 : anticipe les heures de prix extrêmes sur
 * l'ensemble de l'horizon plutôt que de comparer localement à une moyenne
 * glissante (limite connue de SimpleArbitrageEngine — voir l'exemple dans
 * AdvancedArbitrageEngineTest où V1 "gâche" sa capacité batterie sur une
 * heure moyennement chère et rate le vrai pic de la journée).
 *
 * Principe : "merit order" en deux phases plutôt qu'une vraie programmation
 * linéaire multi-périodes.
 *
 *  Phase 1 (planification, sans état) : à partir de prévisions PV/conso
 *  éventuellement dégradées par une marge de prudence (incertitude de
 *  prévision), on liste toutes les heures en surplus (candidates à la
 *  charge) et toutes les heures en déficit (candidates à la décharge) sur
 *  TOUT l'horizon, triées par prix. On accumule les candidates les moins
 *  chères (charge) / les plus chères (décharge) jusqu'à épuiser la capacité
 *  de la batterie (son "headroom" initial), ce qui définit deux seuils de
 *  prix : chargeThreshold et dischargeThreshold.
 *
 *  Phase 2 (dispatch, avec état) : on rejoue l'horizon heure par heure,
 *  comme en V1, mais la décision de charger/décharger ne dépend plus d'une
 *  moyenne locale : elle dépend de la position du prix de l'heure par
 *  rapport aux seuils globaux calculés en phase 1. Le dispatch physique
 *  utilise les valeurs réelles (context->pv()/consumption()), pas les
 *  prévisions dégradées de la phase 1 — la marge de prudence influence quelles
 *  heures sont sélectionnées, pas les quantités réellement mises en jeu.
 *
 * Limites assumées (pistes pour aller plus loin, voir aussi docs/domain-model.md) :
 *  - Le budget de charge/décharge est calculé UNE FOIS à partir de l'état
 *    initial de la batterie, pas replanifié à chaque heure : sur un horizon
 *    avec plusieurs cycles de charge/décharge distincts, ce n'est qu'une
 *    approximation. Une vraie MPC (model predictive control) replanifierait
 *    à chaque pas de temps avec l'état réel de la batterie.
 *  - Le tri par prix ignore le couplage temporel du SOC (l'ordre dans lequel
 *    les heures se présentent n'est pas contraint par le tri) : c'est
 *    exact pour un problème d'arbitrage pur à batterie "surdimensionnée",
 *    mais reste une heuristique en présence de contraintes de puissance
 *    fortes. Une résolution exacte nécessiterait un programme linéaire
 *    (variables : charge/décharge par heure, contraintes : dynamique du
 *    SOC, bornes de puissance, bornes de SOC) résolu via un solveur externe
 *    (ex. GLPK, HiGHS) ou une librairie de MILP.
 *  - L'incertitude de prévision est modélisée par une simple marge relative
 *    (ex. -10 % sur le PV, +10 % sur la conso), pas par une vraie
 *    distribution de probabilité ; une approche par scénarios (plusieurs
 *    tirages de prévision, optimisation robuste sur le pire cas ou en
 *    espérance) serait l'étape suivante naturelle.
 */
final class AdvancedArbitrageEngine implements ArbitrageEngineInterface
{
    public const MODE = 'advanced';

    /**
     * @param  float  $pvForecastSafetyMargin  Fraction retranchée à la prévision PV (0.10 = -10%) pour rester prudent face à l'incertitude météo.
     * @param  float  $consumptionForecastSafetyMargin  Fraction ajoutée à la prévision de consommation (0.10 = +10%).
     */
    public function __construct(
        private readonly float $pvForecastSafetyMargin = 0.0,
        private readonly float $consumptionForecastSafetyMargin = 0.0,
    ) {}

    public function plan(ArbitrageContext $context): ArbitragePlan
    {
        $horizon = $context->horizon();
        $pv = $context->pv();
        $consumption = $context->consumption();
        $battery = $context->battery();
        $maxExportEnergy = $context->maxExportPower()->toEnergy(1.0);

        $pricesByHour = ContractPriceResolver::resolve($context);

        // --- Phase 1 : prévisions (dégradées) + seuils de prix globaux -----
        $actualPv = [];
        $actualDemand = [];
        $surplusCandidates = [];
        $deficitCandidates = [];

        foreach ($horizon->iterateHours() as $hourIndex => $hourStart) {
            $actualPv[$hourIndex] = $pv->productionAt($hourStart, $hourIndex);
            $actualDemand[$hourIndex] = $consumption->consumptionAt($hourStart, $hourIndex);

            $forecastPv = $actualPv[$hourIndex]->multiply(1 - $this->pvForecastSafetyMargin);
            $forecastDemand = $actualDemand[$hourIndex]->multiply(1 + $this->consumptionForecastSafetyMargin);

            $forecastConsumptionFromPv = Energy::min($forecastPv, $forecastDemand);
            $forecastSurplus = $forecastPv->subtract($forecastConsumptionFromPv);
            $forecastDeficit = $forecastDemand->subtract($forecastConsumptionFromPv);

            if (! $forecastSurplus->isZero()) {
                $surplusCandidates[] = ['hour' => $hourIndex, 'price' => $pricesByHour[$hourIndex], 'energy' => $forecastSurplus];
            }

            if (! $forecastDeficit->isZero()) {
                $deficitCandidates[] = ['hour' => $hourIndex, 'price' => $pricesByHour[$hourIndex], 'energy' => $forecastDeficit];
            }
        }

        $chargeThreshold = $this->computeChargeThreshold($surplusCandidates, $battery);
        $dischargeThreshold = $this->computeDischargeThreshold($deficitCandidates, $battery);

        // --- Phase 2 : dispatch heure par heure sur les valeurs réelles ----
        $hours = [];

        foreach ($horizon->iterateHours() as $hourIndex => $hourStart) {
            $priceNow = $pricesByHour[$hourIndex];
            $pvProduction = $actualPv[$hourIndex];
            $demand = $actualDemand[$hourIndex];

            $consumptionFromPv = Energy::min($pvProduction, $demand);
            $surplus = $pvProduction->subtract($consumptionFromPv);
            $deficit = $demand->subtract($consumptionFromPv);

            $batteryCharge = Energy::zero();
            $batteryDischarge = Energy::zero();
            $consumptionFromBattery = Energy::zero();
            $exportToGrid = Energy::zero();

            if (! $surplus->isZero()) {
                $canCharge = ! $battery->chargeHeadroom()->isZero()
                    && $chargeThreshold !== null
                    && ! $priceNow->isGreaterThan($chargeThreshold);

                if ($canCharge) {
                    $batteryCharge = Energy::min($surplus, $battery->maxChargeableEnergy());
                    $battery = $battery->charge($batteryCharge);
                }

                $exportToGrid = Energy::min($surplus->subtract($batteryCharge), $maxExportEnergy);
            } elseif (! $deficit->isZero()) {
                $canDischarge = ! $battery->dischargeHeadroom()->isZero()
                    && $dischargeThreshold !== null
                    && ! $priceNow->isLessThan($dischargeThreshold);

                if ($canDischarge) {
                    $batteryDischarge = Energy::min($deficit, $battery->maxDischargeableEnergy());
                    $battery = $battery->discharge($batteryDischarge);
                }

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
     * Seuil de prix en-dessous duquel on autorise la charge : les heures de
     * surplus prévu sont triées par prix croissant, et on accumule l'énergie
     * "entrante" (côté réseau/PV, avant rendement de charge) jusqu'à
     * épuiser le headroom de charge initial de la batterie. Le prix de la
     * dernière heure retenue devient le seuil. `null` si aucune heure ne
     * doit être retenue (pas de surplus prévu, ou batterie déjà pleine).
     *
     * @param  list<array{hour:int, price:Money, energy:Energy}>  $surplusCandidates
     */
    private function computeChargeThreshold(array $surplusCandidates, Battery $battery): ?Money
    {
        $budget = $battery->chargeHeadroom()->kwh() / $battery->chargeEfficiency();

        usort($surplusCandidates, fn (array $a, array $b) => $a['price']->isGreaterThan($b['price']) ? 1 : -1);

        return $this->accumulateThreshold($surplusCandidates, $budget, $battery->maxChargePower()->toEnergy(1.0));
    }

    /**
     * Seuil de prix au-dessus duquel on autorise la décharge : les heures de
     * déficit prévu sont triées par prix décroissant, et on accumule
     * l'énergie "sortante" (côté compteur, après rendement de décharge)
     * jusqu'à épuiser le headroom de décharge initial de la batterie.
     *
     * @param  list<array{hour:int, price:Money, energy:Energy}>  $deficitCandidates
     */
    private function computeDischargeThreshold(array $deficitCandidates, Battery $battery): ?Money
    {
        $budget = $battery->dischargeHeadroom()->kwh() * $battery->dischargeEfficiency();

        usort($deficitCandidates, fn (array $a, array $b) => $b['price']->isGreaterThan($a['price']) ? 1 : -1);

        return $this->accumulateThreshold($deficitCandidates, $budget, $battery->maxDischargePower()->toEnergy(1.0));
    }

    /**
     * @param  list<array{hour:int, price:Money, energy:Energy}>  $sortedCandidates  Meilleures candidates en premier.
     */
    private function accumulateThreshold(array $sortedCandidates, float $budgetKwh, Energy $perHourCap): ?Money
    {
        $accumulatedKwh = 0.0;
        $threshold = null;

        foreach ($sortedCandidates as $candidate) {
            if ($accumulatedKwh >= $budgetKwh) {
                break;
            }

            $cappedEnergy = Energy::min($candidate['energy'], $perHourCap);
            $accumulatedKwh += $cappedEnergy->kwh();
            $threshold = $candidate['price'];
        }

        return $threshold;
    }
}
