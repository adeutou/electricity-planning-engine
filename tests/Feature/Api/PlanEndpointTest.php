<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPlan(array $overrides = []): string
    {
        $response = $this->postJson('/api/simulate', array_replace_recursive([
            'contract' => [
                'country_code' => 'FR',
                'zone' => 'FR',
                'contract_type' => 'fixed',
                'pricing_config' => ['price_per_kwh' => 0.20],
            ],
            'horizon' => ['start' => '2026-07-20T00:00:00+02:00', 'end' => '2026-07-21T00:00:00+02:00'],
            'mode' => 'simple',
        ], $overrides));

        return $response->json('data.id');
    }

    public function test_it_returns_a_previously_persisted_plan(): void
    {
        $id = $this->createPlan();

        $this->getJson("/api/plans/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonCount(24, 'data.hours');
    }

    public function test_it_returns_404_for_an_unknown_plan(): void
    {
        $this->getJson('/api/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound();
    }

    public function test_chart_data_returns_one_value_per_hour_for_every_series(): void
    {
        // PV et batterie neutralisés explicitement : seul un scénario
        // 100% réseau donne un coût cumulé prévisible à comparer.
        $id = $this->createPlan([
            'pv' => ['peak_power_kwc' => 0],
            'battery' => ['capacity_kwh' => 0, 'soc_min_percent' => 0, 'initial_soc_percent' => 0],
        ]);

        $response = $this->getJson("/api/plans/{$id}/chart-data");

        $response->assertOk();
        $response->assertJsonPath('id', $id);
        $response->assertJsonCount(24, 'labels');
        $response->assertJsonCount(24, 'series.price_eur_per_kwh');
        $response->assertJsonCount(24, 'series.cumulative_cost_eur');

        // Le coût cumulé de la dernière heure doit correspondre au coût
        // total facturé sur 24h à prix fixe (0.20 EUR/kWh, 15 kWh/jour par défaut).
        $cumulative = $response->json('series.cumulative_cost_eur');
        self::assertEqualsWithDelta(15 * 0.20, end($cumulative), 1e-2);
    }

    public function test_chart_data_returns_404_for_an_unknown_plan(): void
    {
        $this->getJson('/api/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV/chart-data')
            ->assertNotFound();
    }
}
