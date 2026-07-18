<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Ports\SimulationPlanRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\SimulationPlanResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlanController extends Controller
{
    public function __construct(
        private readonly SimulationPlanRepositoryInterface $plans,
    ) {}

    public function show(Request $request, string $id): SimulationPlanResource
    {
        $record = $this->plans->findById($id);

        if ($record === null) {
            throw new NotFoundHttpException("Simulation plan '{$id}' not found.");
        }

        return new SimulationPlanResource($record);
    }
}
