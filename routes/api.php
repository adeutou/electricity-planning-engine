<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChartDataController;
use App\Http\Controllers\Api\ChartImageController;
use App\Http\Controllers\Api\HomeAssistantExportController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\SimulationController;
use Illuminate\Support\Facades\Route;

Route::post('/simulate', [SimulationController::class, 'store'])->name('simulate');
Route::get('/plans/{id}', [PlanController::class, 'show'])->name('plans.show');
Route::get('/plans/{id}/chart-data', [ChartDataController::class, 'show'])->name('plans.chart-data');
Route::get('/plans/{id}/chart.svg', [ChartImageController::class, 'show'])->name('plans.chart-image');
Route::post('/plans/{id}/export/home-assistant', [HomeAssistantExportController::class, 'store'])->name('plans.export.home-assistant');
Route::get('/prices', [PriceController::class, 'index'])->name('prices.index');
