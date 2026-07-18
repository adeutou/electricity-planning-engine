<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SimulateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function peakOffPeakPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'contract' => [
                'name' => 'Demo HP/HC',
                'country_code' => 'FR',
                'zone' => 'FR',
                'contract_type' => 'peak_off_peak',
                'pricing_config' => [
                    'off_peak_slots' => [['start' => '22:00', 'end' => '06:00']],
                    'seasons' => [[
                        'label' => 'year_round',
                        'months' => range(1, 12),
                        'rates' => [
                            ['slot' => 'peak', 'price_per_kwh' => 0.27],
                            ['slot' => 'off_peak', 'price_per_kwh' => 0.20],
                        ],
                    ]],
                ],
            ],
            'horizon' => [
                'start' => '2026-07-20T00:00:00+02:00',
                'end' => '2026-07-21T00:00:00+02:00',
                'timezone' => 'Europe/Paris',
            ],
            'mode' => 'simple',
        ], $overrides);
    }

    public function test_it_returns_a_complete_plan_for_a_valid_request(): void
    {
        $response = $this->postJson('/api/simulate', $this->peakOffPeakPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.zone', 'FR');
        $response->assertJsonPath('data.mode', 'simple');
        $response->assertJsonCount(24, 'data.hours');
        $response->assertJsonStructure([
            'data' => [
                'id',
                'zone',
                'mode',
                'totals' => ['cost_eur', 'consumption_kwh', 'pv_production_kwh', 'export_kwh'],
                'hours' => [
                    '*' => [
                        'hour_index', 'starts_at', 'price_eur_per_kwh', 'consumption_kwh',
                        'pv_production_kwh', 'consumption_from_grid_kwh', 'consumption_from_pv_kwh',
                        'consumption_from_battery_kwh', 'battery_charge_kwh', 'battery_discharge_kwh',
                        'export_to_grid_kwh', 'soc_end_of_hour_kwh', 'cost_eur',
                    ],
                ],
            ],
        ]);

        // Une heure "peak" (14h) doit être facturée exactement au tarif HP déclaré.
        $hour14 = $response->json('data.hours.14');
        self::assertSame(14, $hour14['hour_index']);
        self::assertEqualsWithDelta(0.27, $hour14['price_eur_per_kwh'], 1e-9);

        // Les métadonnées de snapshot (provider, horizon, timezone) ne sont
        // exposées qu'à la relecture persistée (GET /api/plans/{id}), pas
        // sur la réponse immédiate du POST (cf. SimulationPlanResource).
        $id = $response->json('data.id');
        $this->getJson("/api/plans/{$id}")->assertJsonStructure([
            'data' => ['price_provider', 'horizon_start', 'horizon_end', 'timezone'],
        ]);
    }

    public function test_it_supports_advanced_mode(): void
    {
        $response = $this->postJson('/api/simulate', $this->peakOffPeakPayload(['mode' => 'advanced']));

        $response->assertCreated();
        $response->assertJsonPath('data.mode', 'advanced');
    }

    public function test_it_persists_the_plan_so_it_can_be_retrieved_later(): void
    {
        $response = $this->postJson('/api/simulate', $this->peakOffPeakPayload());

        $id = $response->json('data.id');

        $this->getJson("/api/plans/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    public function test_it_rejects_a_request_missing_required_contract_fields(): void
    {
        $payload = $this->peakOffPeakPayload();
        unset($payload['contract']['country_code']);

        $this->postJson('/api/simulate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract.country_code']);
    }

    public function test_it_rejects_an_unknown_contract_type(): void
    {
        $payload = $this->peakOffPeakPayload(['contract' => ['contract_type' => 'not_a_real_type']]);

        $this->postJson('/api/simulate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract.contract_type']);
    }

    public function test_it_rejects_a_horizon_that_is_not_a_whole_number_of_hours(): void
    {
        $payload = $this->peakOffPeakPayload([
            'horizon' => ['start' => '2026-07-20T00:00:00+02:00', 'end' => '2026-07-20T05:30:00+02:00'],
        ]);

        $this->postJson('/api/simulate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['horizon.end']);
    }

    public function test_it_rejects_pricing_config_missing_the_keys_required_by_the_contract_type(): void
    {
        $payload = $this->peakOffPeakPayload([
            'contract' => ['contract_type' => 'fixed', 'pricing_config' => ['unrelated_key' => true]],
        ]);

        $this->postJson('/api/simulate', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract.pricing_config.price_per_kwh']);
    }

    public function test_it_maps_a_domain_invariant_violation_to_a_422_instead_of_a_500(): void
    {
        // socMin >= socMax : chaque champ pris individuellement passe la
        // validation Http (0-100), seul le value object du domaine le rejette.
        $payload = $this->peakOffPeakPayload([
            'battery' => ['soc_min_percent' => 80, 'soc_max_percent' => 20],
        ]);

        $response = $this->postJson('/api/simulate', $payload);

        $response->assertStatus(422);
        self::assertStringContainsString('socMin', $response->json('message'));
    }

    public function test_it_accepts_partial_battery_overrides_and_falls_back_to_config_defaults(): void
    {
        $payload = $this->peakOffPeakPayload(['battery' => ['capacity_kwh' => 3.0]]);

        $this->postJson('/api/simulate', $payload)->assertCreated();
    }
}
