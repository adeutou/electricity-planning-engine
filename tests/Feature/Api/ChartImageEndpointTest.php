<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChartImageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createPlan(): string
    {
        $response = $this->postJson('/api/simulate', [
            'contract' => [
                'country_code' => 'FR',
                'zone' => 'FR',
                'contract_type' => 'fixed',
                'pricing_config' => ['price_per_kwh' => 0.20],
            ],
            'horizon' => ['start' => '2026-07-20T00:00:00+02:00', 'end' => '2026-07-21T00:00:00+02:00'],
            'mode' => 'simple',
        ]);

        return $response->json('data.id');
    }

    public function test_it_returns_a_valid_svg_document(): void
    {
        $id = $this->createPlan();

        $response = $this->get("/api/plans/{$id}/chart.svg");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');

        $body = $response->getContent();
        self::assertStringContainsString('<svg', $body);
        self::assertStringContainsString('</svg>', $body);
    }

    public function test_it_returns_404_for_an_unknown_plan(): void
    {
        $this->get('/api/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV/chart.svg')
            ->assertNotFound();
    }
}
