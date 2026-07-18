<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Arbitrage\SimulateArbitrageUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimulateRequest;
use App\Http\Resources\SimulationPlanResource;
use Illuminate\Http\JsonResponse;

final class SimulationController extends Controller
{
    public function __construct(
        private readonly SimulateArbitrageUseCase $useCase,
    ) {}

    public function store(SimulateRequest $request): JsonResponse
    {
        $result = $this->useCase->handle($request->toDto());

        return (new SimulationPlanResource($result))
            ->response()
            ->setStatusCode(201);
    }
}
