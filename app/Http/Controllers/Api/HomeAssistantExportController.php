<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Ports\HomeAssistantExporterInterface;
use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bonus : pousse un plan déjà calculé vers Home Assistant (voir
 * App\Infrastructure\HomeAssistant\HomeAssistantWebhookExporter et
 * docs/home-assistant-integration.md pour la configuration côté HA).
 */
final class HomeAssistantExportController extends Controller
{
    public function __construct(
        private readonly SimulationPlanRepositoryInterface $plans,
        private readonly HomeAssistantExporterInterface $exporter,
    ) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $record = $this->plans->findById($id);

        if ($record === null) {
            throw new NotFoundHttpException("Simulation plan '{$id}' not found.");
        }

        $this->exporter->export($record);

        return response()->json([
            'message' => "Plan '{$id}' exported to Home Assistant.",
        ]);
    }
}
