<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PriceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_one_price_point_per_hour_in_the_requested_window(): void
    {
        $response = $this->getJson('/api/prices?'.http_build_query([
            'zone' => 'FR',
            'from' => '2026-07-20T00:00:00+02:00',
            'to' => '2026-07-20T06:00:00+02:00',
        ]));

        $response->assertOk();
        $response->assertJsonCount(6, 'data');
        $response->assertJsonStructure([
            'data' => ['*' => ['zone', 'source', 'timestamp', 'resolution_minutes', 'price_eur_per_mwh', 'price_eur_per_kwh']],
        ]);
        $response->assertJsonPath('data.0.zone', 'FR');
        $response->assertJsonPath('data.0.source', 'mock');
    }

    public function test_it_serves_the_second_call_from_cache_with_identical_prices(): void
    {
        $query = '?'.http_build_query([
            'zone' => 'FR',
            'from' => '2026-07-20T00:00:00+02:00',
            'to' => '2026-07-20T06:00:00+02:00',
        ]);

        $first = $this->getJson('/api/prices'.$query)->json('data');
        $second = $this->getJson('/api/prices'.$query)->json('data');

        self::assertSame(
            array_column($first, 'price_eur_per_mwh'),
            array_column($second, 'price_eur_per_mwh'),
            'the mock provider is randomized, so identical results across calls prove the cache was used'
        );
    }

    public function test_it_requires_a_zone(): void
    {
        $this->getJson('/api/prices?'.http_build_query([
            'from' => '2026-07-20T00:00:00+02:00',
            'to' => '2026-07-20T06:00:00+02:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['zone']);
    }

    public function test_it_rejects_a_to_date_before_from(): void
    {
        $this->getJson('/api/prices?'.http_build_query([
            'zone' => 'FR',
            'from' => '2026-07-20T06:00:00+02:00',
            'to' => '2026-07-20T00:00:00+02:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }
}
