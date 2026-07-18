<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bonus : expose un plan déjà calculé sous une forme directement exploitable
 * par une librairie de graphique (séries parallèles indexées par heure,
 * plutôt que la liste d'objets renvoyée par PlanController). Volontairement
 * un JSON "plat" sans Http Resource dédiée : c'est une simple reprojection
 * de données déjà validées, pas une nouvelle représentation métier.
 */
final class ChartDataController extends Controller
{
    public function __construct(
        private readonly SimulationPlanRepositoryInterface $plans,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->plans->findById($id);

        if ($record === null) {
            throw new NotFoundHttpException("Simulation plan '{$id}' not found.");
        }

        $labels = [];
        $prices = [];
        $pvProduction = [];
        $consumption = [];
        $batteryCharge = [];
        $batteryDischarge = [];
        $cumulativeCost = [];

        $runningCost = 0.0;

        foreach ($record->plan as $hour) {
            $runningCost += $hour->cost()->amount();

            $labels[] = $hour->startsAt()->format(DATE_ATOM);
            $prices[] = round($hour->pricePerKwh()->amount(), 6);
            $pvProduction[] = round($hour->pvProduction()->kwh(), 4);
            $consumption[] = round($hour->consumption()->kwh(), 4);
            $batteryCharge[] = round($hour->batteryCharge()->kwh(), 4);
            $batteryDischarge[] = round($hour->batteryDischarge()->kwh(), 4);
            $cumulativeCost[] = round($runningCost, 4);
        }

        return response()->json([
            'id' => $record->id,
            'labels' => $labels,
            'series' => [
                'price_eur_per_kwh' => $prices,
                'pv_production_kwh' => $pvProduction,
                'consumption_kwh' => $consumption,
                'battery_charge_kwh' => $batteryCharge,
                'battery_discharge_kwh' => $batteryDischarge,
                'cumulative_cost_eur' => $cumulativeCost,
            ],
        ]);
    }
}
