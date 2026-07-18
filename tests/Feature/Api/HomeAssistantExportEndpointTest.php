<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HomeAssistantExportEndpointTest extends TestCase
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

    public function test_it_pushes_the_plan_to_the_configured_webhook(): void
    {
        config(['energy.home_assistant.webhook_url' => 'https://ha.example.test/api/webhook/test-hook']);
        Http::fake(['ha.example.test/*' => Http::response(['ok' => true], 200)]);

        $id = $this->createPlan();

        $this->postJson("/api/plans/{$id}/export/home-assistant")
            ->assertOk()
            ->assertJsonPath('message', "Plan '{$id}' exported to Home Assistant.");

        Http::assertSent(function ($request) use ($id) {
            return $request->url() === 'https://ha.example.test/api/webhook/test-hook'
                && $request['plan_id'] === $id
                && $request['zone'] === 'FR'
                && count($request['hours']) === 24
                && array_key_exists('recommended_action', $request['hours'][0]);
        });
    }

    public function test_it_returns_503_when_the_webhook_url_is_not_configured(): void
    {
        config(['energy.home_assistant.webhook_url' => null]);

        $id = $this->createPlan();

        $response = $this->postJson("/api/plans/{$id}/export/home-assistant");

        $response->assertStatus(503);
        self::assertStringContainsString('HOME_ASSISTANT_WEBHOOK_URL', $response->json('message'));
    }

    public function test_it_returns_503_when_home_assistant_rejects_the_webhook(): void
    {
        config(['energy.home_assistant.webhook_url' => 'https://ha.example.test/api/webhook/test-hook']);
        Http::fake(['ha.example.test/*' => Http::response('nope', 500)]);

        $id = $this->createPlan();

        $this->postJson("/api/plans/{$id}/export/home-assistant")
            ->assertStatus(503);
    }

    public function test_it_returns_404_for_an_unknown_plan(): void
    {
        config(['energy.home_assistant.webhook_url' => 'https://ha.example.test/api/webhook/test-hook']);

        $this->postJson('/api/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV/export/home-assistant')
            ->assertNotFound();
    }
}
