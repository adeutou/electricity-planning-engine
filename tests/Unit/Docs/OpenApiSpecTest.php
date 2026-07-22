<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use App\Application\Arbitrage\Engine\AdvancedArbitrageEngine;
use App\Application\Arbitrage\Engine\SimpleArbitrageEngine;
use App\Domain\Contract\Enum\ContractType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/openapi.yaml is hand-written and, per the README, "verified against
 * the actual Form Requests and Resources, not written from memory". These
 * tests keep that promise enforced: they parse the spec and check both its
 * internal consistency and its agreement with routes/api.php and the real
 * enums used by the validation layer, so the two cannot silently drift.
 */
final class OpenApiSpecTest extends TestCase
{
    private const SPEC_PATH = __DIR__.'/../../../docs/openapi.yaml';

    /** @var array<string, mixed> */
    private static array $spec;

    public static function setUpBeforeClass(): void
    {
        self::$spec = Yaml::parseFile(self::SPEC_PATH);
    }

    public function test_the_spec_file_exists_and_parses_as_valid_yaml(): void
    {
        self::assertFileExists(self::SPEC_PATH);
        self::assertIsArray(self::$spec);
    }

    public function test_top_level_openapi_metadata(): void
    {
        self::assertSame('3.0.3', self::$spec['openapi']);
        self::assertSame('Electricity Planning Engine API', self::$spec['info']['title']);
        self::assertSame('1.0.0', self::$spec['info']['version']);
        self::assertSame('MIT', self::$spec['info']['license']['name']);
    }

    public function test_tags_cover_exactly_the_four_documented_areas(): void
    {
        $names = array_column(self::$spec['tags'], 'name');

        self::assertSame(['Simulation', 'Plans', 'Prices', 'Home Assistant'], $names);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pathMethodProvider(): iterable
    {
        yield 'simulate' => ['/api/simulate', 'post'];
        yield 'plan show' => ['/api/plans/{id}', 'get'];
        yield 'plan chart data' => ['/api/plans/{id}/chart-data', 'get'];
        yield 'plan chart svg' => ['/api/plans/{id}/chart.svg', 'get'];
        yield 'home assistant export' => ['/api/plans/{id}/export/home-assistant', 'post'];
        yield 'prices' => ['/api/prices', 'get'];
    }

    #[DataProvider('pathMethodProvider')]
    public function test_every_documented_route_is_present_with_the_right_http_method(string $path, string $method): void
    {
        self::assertArrayHasKey($path, self::$spec['paths'], "Path {$path} is missing from the spec.");
        self::assertArrayHasKey($method, self::$spec['paths'][$path], "Method {$method} is missing for {$path}.");
    }

    public function test_the_documented_paths_match_exactly_the_registered_api_routes(): void
    {
        $routesFile = __DIR__.'/../../../routes/api.php';
        $contents = file_get_contents($routesFile);

        self::assertNotFalse($contents);

        preg_match_all('/Route::(get|post|put|patch|delete)\(\'([^\']+)\'/', $contents, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'Expected to find at least one Route:: declaration in routes/api.php.');

        $registered = [];
        foreach ($matches as [, $method, $uri]) {
            $registered[] = [$method, '/api'.$uri];
        }

        $documented = [];
        foreach (self::$spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = [$method, $path];
            }
        }

        self::assertCount(
            count($registered),
            $documented,
            'Number of operations documented in openapi.yaml should match the number of routes registered in routes/api.php.'
        );

        foreach ($registered as $route) {
            self::assertContains(
                $route,
                $documented,
                sprintf('Route %s %s is registered but not documented in openapi.yaml.', strtoupper($route[0]), $route[1])
            );
        }
    }

    public function test_simulate_request_body_requires_only_contract_horizon_and_mode(): void
    {
        $schema = self::$spec['components']['schemas']['SimulateRequestBody'];

        self::assertSame(['contract', 'horizon', 'mode'], $schema['required']);
    }

    public function test_mode_enum_matches_the_actual_arbitrage_engine_mode_constants(): void
    {
        $documented = self::$spec['components']['schemas']['SimulateRequestBody']['properties']['mode']['enum'];

        self::assertSame([SimpleArbitrageEngine::MODE, AdvancedArbitrageEngine::MODE], $documented);
    }

    public function test_contract_type_enum_matches_the_actual_contract_type_enum_cases(): void
    {
        $documented = self::$spec['components']['schemas']['EnergyContractInput']['properties']['contract_type']['enum'];
        $actual = array_map(static fn (ContractType $type) => $type->value, ContractType::cases());

        self::assertSame($actual, $documented);
    }

    public function test_price_provider_enum_is_mock_or_entsoe_wherever_it_appears(): void
    {
        $bodyEnum = self::$spec['components']['schemas']['SimulateRequestBody']['properties']['price_provider']['enum'];
        self::assertSame(['mock', 'entsoe'], $bodyEnum);

        $queryParameters = self::indexParametersByName(self::$spec['paths']['/api/prices']['get']['parameters']);
        self::assertSame(['mock', 'entsoe'], $queryParameters['provider']['schema']['enum']);
    }

    public function test_simulate_responses_are_201_and_422_only(): void
    {
        $responses = self::$spec['paths']['/api/simulate']['post']['responses'];

        self::assertSame(['201', '422'], array_keys($responses));
    }

    public function test_plan_show_responses_are_200_and_404_only(): void
    {
        $responses = self::$spec['paths']['/api/plans/{id}']['get']['responses'];

        self::assertSame(['200', '404'], array_keys($responses));
    }

    public function test_chart_data_and_chart_svg_both_can_404_for_an_unknown_plan(): void
    {
        self::assertArrayHasKey('404', self::$spec['paths']['/api/plans/{id}/chart-data']['get']['responses']);
        self::assertArrayHasKey('404', self::$spec['paths']['/api/plans/{id}/chart.svg']['get']['responses']);
    }

    public function test_chart_svg_response_content_type_is_svg_xml(): void
    {
        $responses = self::$spec['paths']['/api/plans/{id}/chart.svg']['get']['responses'];

        self::assertArrayHasKey('image/svg+xml', $responses['200']['content']);
    }

    public function test_home_assistant_export_responses_are_200_404_and_503(): void
    {
        $responses = self::$spec['paths']['/api/plans/{id}/export/home-assistant']['post']['responses'];

        self::assertSame(['200', '404', '503'], array_keys($responses));
    }

    public function test_prices_query_parameters_zone_from_and_to_are_required_while_provider_is_not(): void
    {
        $byName = self::indexParametersByName(self::$spec['paths']['/api/prices']['get']['parameters']);

        self::assertTrue($byName['zone']['required']);
        self::assertTrue($byName['from']['required']);
        self::assertTrue($byName['to']['required']);
        self::assertFalse($byName['provider']['required']);
    }

    public function test_prices_responses_are_200_and_422_only(): void
    {
        $responses = self::$spec['paths']['/api/prices']['get']['responses'];

        self::assertSame(['200', '422'], array_keys($responses));
    }

    public function test_plan_id_path_parameter_component_is_required_and_reused_by_every_plan_route(): void
    {
        $planId = self::$spec['components']['parameters']['PlanId'];

        self::assertSame('id', $planId['name']);
        self::assertSame('path', $planId['in']);
        self::assertTrue($planId['required']);

        foreach (['/api/plans/{id}', '/api/plans/{id}/chart-data', '/api/plans/{id}/chart.svg', '/api/plans/{id}/export/home-assistant'] as $path) {
            $operation = self::$spec['paths'][$path][array_key_first(self::$spec['paths'][$path])];
            self::assertSame(
                ['$ref' => '#/components/parameters/PlanId'],
                $operation['parameters'][0],
                "Expected {$path} to reuse the shared PlanId parameter."
            );
        }
    }

    public function test_all_expected_schemas_are_defined(): void
    {
        $expected = [
            'SimulateRequestBody', 'EnergyContractInput', 'HorizonInput', 'BatteryInput',
            'PvInput', 'ConsumptionInput', 'PlanHour', 'SimulationPlanEnvelope',
            'ChartData', 'PricePoint', 'ValidationErrorResponse',
        ];

        foreach ($expected as $schemaName) {
            self::assertArrayHasKey($schemaName, self::$spec['components']['schemas'], "Missing schema: {$schemaName}");
        }
    }

    public function test_simulate_request_examples_are_internally_consistent(): void
    {
        $examples = self::$spec['paths']['/api/simulate']['post']['requestBody']['content']['application/json']['examples'];

        self::assertSame('simple', $examples['peakOffPeak']['value']['mode']);
        self::assertSame('peak_off_peak', $examples['peakOffPeak']['value']['contract']['contract_type']);

        self::assertSame('advanced', $examples['dynamicSpot']['value']['mode']);
        self::assertSame('dynamic_spot', $examples['dynamicSpot']['value']['contract']['contract_type']);

        foreach ($examples as $name => $example) {
            self::assertNotEmpty($example['value']['contract']['country_code'], "Example {$name} is missing a country_code.");
            self::assertContains($example['value']['contract']['contract_type'], ['fixed', 'peak_off_peak', 'tempo', 'dynamic_spot']);
        }
    }

    public function test_validation_error_response_schema_has_a_message_and_an_optional_per_field_errors_map(): void
    {
        $schema = self::$spec['components']['schemas']['ValidationErrorResponse'];

        self::assertSame('string', $schema['properties']['message']['type']);
        self::assertSame('object', $schema['properties']['errors']['type']);
        self::assertSame('array', $schema['properties']['errors']['additionalProperties']['type']);
    }

    public function test_battery_percentage_fields_are_bounded_between_0_and_100(): void
    {
        $battery = self::$spec['components']['schemas']['BatteryInput']['properties'];

        foreach (['soc_min_percent', 'soc_max_percent', 'initial_soc_percent'] as $field) {
            self::assertSame(0, $battery[$field]['minimum']);
            self::assertSame(100, $battery[$field]['maximum']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     * @return array<string, array<string, mixed>>
     */
    private static function indexParametersByName(array $parameters): array
    {
        $byName = [];
        foreach ($parameters as $parameter) {
            $byName[$parameter['name']] = $parameter;
        }

        return $byName;
    }
}