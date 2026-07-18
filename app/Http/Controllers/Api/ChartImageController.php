<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Infrastructure\Chart\SvgArbitrageChartRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bonus : rendu visuel direct (SVG, aucune dépendance JS) d'un plan déjà
 * calculé — complémentaire à ChartDataController, qui expose les mêmes
 * données en JSON brut pour un rendu côté client.
 */
final class ChartImageController extends Controller
{
    public function __construct(
        private readonly SimulationPlanRepositoryInterface $plans,
        private readonly SvgArbitrageChartRenderer $renderer,
    ) {}

    public function show(Request $request, string $id): Response
    {
        $record = $this->plans->findById($id);

        if ($record === null) {
            throw new NotFoundHttpException("Simulation plan '{$id}' not found.");
        }

        $svg = $this->renderer->render($record->plan);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
