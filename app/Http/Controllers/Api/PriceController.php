<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Pricing\FetchPriceSeriesUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceQueryRequest;
use App\Http\Resources\PricePointResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PriceController extends Controller
{
    public function __construct(
        private readonly FetchPriceSeriesUseCase $useCase,
    ) {}

    public function index(PriceQueryRequest $request): AnonymousResourceCollection
    {
        $series = $this->useCase->handle(
            $request->fromDate(),
            $request->toDate(),
            $request->zone(),
            $request->provider(),
        );

        return PricePointResource::collection(iterator_to_array($series));
    }
}
