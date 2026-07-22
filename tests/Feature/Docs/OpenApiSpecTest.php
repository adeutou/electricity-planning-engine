<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Application\Arbitrage\Engine\AdvancedArbitrageEngine;
use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Domain\Contract\Enum\ContractType;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * docs/openapi.yaml is hand-written documentation, not generated from the
 * routes/Form Requests/Resources it describes. These tests exist to catch
 * drift between the spec and the actual API contract: routes that no longer
 * exist (or new ones missing from the spec), enum values that fall out of
 * sync, and required fields that no longer match validation rules.
 */
final class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(base_path('docs/openapi.yaml'));

        return $parsed;
    }

    public function test_the_file_is_valid_yaml_with_the_expected_openapi_version(): void
    {
        $spec = $this->spec();

        self::assertSame('3.0.3', $spec['openapi']);
        self::assertSame('Electricity Planning Engine API', $spec['info']['title']);
        self::assertArrayHasKey('version', $spec['info']);
        self::assertArrayHasKey('license', $spec['info']);
    }

    public function test_every_documented_path_corresponds_to_a_real_registered_route(): void
    {
        $spec = $this->spec();

        $expectedPathsToMethods = [
            '/api/simulate' => ['post'],
            '/api/plans/{id}' => ['get'],
            '/api/plans/{id}/chart-data' => ['get'],
            '/api/plans/{id}/chart.svg' => ['get'],
            '/api/plans/{id}/export/home-assistant' => ['post'],
            '/api/prices' => ['get'],
        ];

        self::assertSame(
            array_keys($expectedPathsToMethods),
            array_keys($spec['paths']),
            'the spec should document exactly the routes that exist, no more, no less'
        );

        foreach ($expectedPathsToMethods as $path => $methods) {
            self::assertArrayHasKey($path, $spec['paths']);
            self::assertSame($methods, array_keys($spec['paths'][$path]));
        }
    }

    public function test_documented_routes_exist_in_the_actual_route_table_with_matching_uri_and_verb(): void
    {
        $routesByName = [
            'simulate' => ['POST', 'api/simulate'],
            'plans.show' => ['GET', 'api/plans/{id}'],
            'plans.chart-data' => ['GET', 'api/plans/{id}/chart-data'],
            'plans.chart-image' => ['GET', 'api/plans/{id}/chart.svg'],
            'plans.export.home-assistant' => ['POST', 'api/plans/{id}/export/home-assistant'],
            'prices.index' => ['GET', 'api/prices'],
        ];

        foreach ($routesByName as $name => [$verb, $uri]) {
            self::assertTrue(Route::has($name), "expected a route named '{$name}' to exist");

            $route = Route::getRoutes()->getByName($name);

            self::assertNotNull($route);
            self::assertSame($uri, $route->uri());
            self::assertContains($verb, $route->methods());
        }
    }

    public function test_simulate_request_body_requires_exactly_contract_horizon_and_mode(): void
    {
        $schema = $this->spec()['components']['schemas']['SimulateRequestBody'];

        self::assertSame(['contract', 'horizon', 'mode'], $schema['required']);
        self::assertArrayHasKey('battery', $schema['properties']);
        self::assertArrayHasKey('pv', $schema['properties']);
        self::assertArrayHasKey('consumption', $schema['properties']);
        self::assertArrayHasKey('price_provider', $schema['properties']);
        self::assertArrayHasKey('max_export_power_kw', $schema['properties']);
    }

    public function test_mode_enum_matches_the_actual_arbitrage_engine_mode_constants(): void
    {
        $schema = $this->spec()['components']['schemas']['SimulateRequestBody'];

        self::assertSame(
            [SimpleArbitrageEngine::MODE, AdvancedArbitrageEngine::MODE],
            $schema['properties']['mode']['enum']
        );
    }

    public function test_price_provider_enum_matches_the_two_supported_providers(): void
    {
        $schema = $this->spec()['components']['schemas']['SimulateRequestBody'];

        self::assertSame(['mock', 'entsoe'], $schema['properties']['price_provider']['enum']);
    }

    public function test_contract_type_enum_matches_the_domain_contract_type_enum(): void
    {
        $schema = $this->spec()['components']['schemas']['EnergyContractInput'];

        $expected = array_map(static fn (ContractType $type) => $type->value, ContractType::cases());

        self::assertSame($expected, $schema['properties']['contract_type']['enum']);
    }

    public function test_battery_input_field_names_match_the_simulate_request_validation_rules(): void
    {
        $schema = $this->spec()['components']['schemas']['BatteryInput'];

        $expectedFields = [
            'capacity_kwh',
            'max_charge_power_kw',
            'max_discharge_power_kw',
            'round_trip_efficiency',
            'soc_min_percent',
            'soc_max_percent',
            'initial_soc_percent',
        ];

        self::assertSame($expectedFields, array_keys($schema['properties']));
    }

    public function test_pv_and_consumption_input_field_names_match_the_config_defaults(): void
    {
        $spec = $this->spec();

        self::assertSame(
            ['peak_power_kwc', 'hourly_profile_kwh'],
            array_keys($spec['components']['schemas']['PvInput']['properties'])
        );

        self::assertSame(
            ['daily_baseline_kwh', 'hourly_profile_kwh'],
            array_keys($spec['components']['schemas']['ConsumptionInput']['properties'])
        );

        // These are exactly the config/energy.php defaults the request falls
        // back to when a field is omitted; if the config key is renamed, the
        // spec's description referencing it silently goes stale.
        self::assertArrayHasKey('peak_power_kwc', config('energy.pv'));
        self::assertArrayHasKey('daily_baseline_kwh', config('energy.consumption'));
    }

    public function test_plan_hour_schema_field_names_match_the_plan_hour_resource(): void
    {
        $schema = $this->spec()['components']['schemas']['PlanHour'];

        $expectedFields = [
            'hour_index',
            'starts_at',
            'price_eur_per_kwh',
            'consumption_kwh',
            'pv_production_kwh',
            'consumption_from_grid_kwh',
            'consumption_from_pv_kwh',
            'consumption_from_battery_kwh',
            'battery_charge_kwh',
            'battery_discharge_kwh',
            'export_to_grid_kwh',
            'soc_end_of_hour_kwh',
            'cost_eur',
        ];

        self::assertSame($expectedFields, array_keys($schema['properties']));
    }

    public function test_price_point_schema_field_names_match_the_price_point_resource(): void
    {
        $schema = $this->spec()['components']['schemas']['PricePoint'];

        $expectedFields = [
            'zone',
            'source',
            'timestamp',
            'resolution_minutes',
            'price_eur_per_mwh',
            'price_eur_per_kwh',
        ];

        self::assertSame($expectedFields, array_keys($schema['properties']));
    }

    public function test_simulate_endpoint_documents_201_on_success_and_422_on_failure(): void
    {
        $responses = $this->spec()['paths']['/api/simulate']['post']['responses'];

        self::assertArrayHasKey('201', $responses);
        self::assertArrayHasKey('422', $responses);
        self::assertArrayNotHasKey('200', $responses, 'POST /api/simulate persists a resource, it should document 201, not 200');
    }

    public function test_plan_endpoints_document_a_404_response(): void
    {
        $spec = $this->spec();

        foreach (['/api/plans/{id}', '/api/plans/{id}/chart-data', '/api/plans/{id}/chart.svg'] as $path) {
            self::assertArrayHasKey('404', $spec['paths'][$path]['get']['responses'], "missing 404 response for {$path}");
        }
    }

    public function test_home_assistant_export_documents_a_503_for_a_missing_or_unreachable_webhook(): void
    {
        $responses = $this->spec()['paths']['/api/plans/{id}/export/home-assistant']['post']['responses'];

        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertArrayHasKey('503', $responses);
    }

    public function test_prices_endpoint_declares_zone_from_and_to_as_required_query_parameters(): void
    {
        $parameters = $this->spec()['paths']['/api/prices']['get']['parameters'];

        $required = [];
        foreach ($parameters as $parameter) {
            if (($parameter['required'] ?? false) === true) {
                $required[] = $parameter['name'];
            }
        }

        self::assertSame(['zone', 'from', 'to'], $required);

        $providerParameter = current(array_filter($parameters, static fn (array $p) => $p['name'] === 'provider'));
        self::assertFalse($providerParameter['required'] ?? true);
        self::assertSame(['mock', 'entsoe'], $providerParameter['schema']['enum']);
    }

    public function test_plan_id_parameter_is_a_required_path_parameter(): void
    {
        $planId = $this->spec()['components']['parameters']['PlanId'];

        self::assertSame('id', $planId['name']);
        self::assertSame('path', $planId['in']);
        self::assertTrue($planId['required']);
    }

    public function test_the_two_documented_request_examples_declare_the_required_top_level_fields(): void
    {
        $examples = $this->spec()['paths']['/api/simulate']['post']['requestBody']['content']['application/json']['examples'];

        self::assertArrayHasKey('peakOffPeak', $examples);
        self::assertArrayHasKey('dynamicSpot', $examples);

        foreach ($examples as $name => $example) {
            $value = $example['value'];

            self::assertArrayHasKey('contract', $value, "example '{$name}' is missing 'contract'");
            self::assertArrayHasKey('horizon', $value, "example '{$name}' is missing 'horizon'");
            self::assertArrayHasKey('mode', $value, "example '{$name}' is missing 'mode'");
            self::assertContains($value['mode'], [SimpleArbitrageEngine::MODE, AdvancedArbitrageEngine::MODE]);
            self::assertContains(
                $value['contract']['contract_type'],
                array_map(static fn (ContractType $type) => $type->value, ContractType::cases())
            );
        }
    }
}