<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/openapi.yaml is hand-written and consumed by Swagger UI (docs/index.html)
 * and published verbatim via .github/workflows/pages.yml. These tests guard the
 * structural contract of the spec itself: valid YAML, the paths/response codes
 * documented in the PR description, and that every internal $ref resolves.
 */
final class OpenApiSpecTest extends TestCase
{
    private static ?array $spec = null;

    private static function spec(): array
    {
        if (self::$spec === null) {
            self::$spec = Yaml::parseFile(self::path());
        }

        return self::$spec;
    }

    private static function path(): string
    {
        return __DIR__.'/../../../docs/openapi.yaml';
    }

    public function test_the_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::path());
        self::assertFileIsReadable(self::path());
    }

    public function test_it_parses_as_valid_yaml(): void
    {
        $spec = self::spec();

        self::assertIsArray($spec);
        self::assertNotEmpty($spec);
    }

    public function test_the_root_document_declares_openapi_3_0_3(): void
    {
        self::assertSame('3.0.3', self::spec()['openapi']);
    }

    public function test_info_block_has_a_title_and_semver_version(): void
    {
        $info = self::spec()['info'];

        self::assertSame('Electricity Planning Engine API', $info['title']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $info['version']);
        self::assertStringContainsString('simple', $info['description']);
        self::assertStringContainsString('advanced', $info['description']);
    }

    public function test_it_declares_the_expected_tags(): void
    {
        $names = array_column(self::spec()['tags'], 'name');

        self::assertSame(['Simulation', 'Plans', 'Prices', 'Home Assistant'], $names);
    }

    public function test_it_declares_exactly_the_six_documented_paths(): void
    {
        self::assertSame(
            [
                '/api/simulate',
                '/api/plans/{id}',
                '/api/plans/{id}/chart-data',
                '/api/plans/{id}/chart.svg',
                '/api/plans/{id}/export/home-assistant',
                '/api/prices',
            ],
            array_keys(self::spec()['paths'])
        );
    }

    public function test_post_simulate_requires_a_body_and_declares_201_and_422(): void
    {
        $operation = self::spec()['paths']['/api/simulate']['post'];

        self::assertSame(['Simulation'], $operation['tags']);
        self::assertTrue($operation['requestBody']['required']);
        self::assertArrayHasKey('201', $operation['responses']);
        self::assertArrayHasKey('422', $operation['responses']);
        self::assertArrayNotHasKey('200', $operation['responses']);
    }

    public function test_post_simulate_declares_both_request_examples(): void
    {
        $examples = self::spec()['paths']['/api/simulate']['post']['requestBody']['content']['application/json']['examples'];

        self::assertArrayHasKey('peakOffPeak', $examples);
        self::assertArrayHasKey('dynamicSpot', $examples);
        self::assertSame('simple', $examples['peakOffPeak']['value']['mode']);
        self::assertSame('advanced', $examples['dynamicSpot']['value']['mode']);
    }

    public function test_plan_endpoints_reuse_the_shared_plan_id_parameter(): void
    {
        $paths = self::spec()['paths'];

        foreach (['/api/plans/{id}', '/api/plans/{id}/chart-data', '/api/plans/{id}/chart.svg', '/api/plans/{id}/export/home-assistant'] as $path) {
            $method = $path === '/api/plans/{id}/export/home-assistant' ? 'post' : 'get';

            self::assertSame(
                [['$ref' => '#/components/parameters/PlanId']],
                $paths[$path][$method]['parameters'],
                "expected {$path} to reuse the shared PlanId parameter"
            );
            self::assertArrayHasKey('404', $paths[$path][$method]['responses']);
        }
    }

    public function test_chart_svg_endpoint_declares_an_svg_content_type(): void
    {
        $responses = self::spec()['paths']['/api/plans/{id}/chart.svg']['get']['responses']['200'];

        self::assertArrayHasKey('image/svg+xml', $responses['content']);
    }

    public function test_get_prices_declares_zone_from_and_to_as_required_query_parameters(): void
    {
        $parameters = self::spec()['paths']['/api/prices']['get']['parameters'];
        $byName = array_column($parameters, null, 'name');

        self::assertTrue($byName['zone']['required']);
        self::assertTrue($byName['from']['required']);
        self::assertTrue($byName['to']['required']);
        self::assertArrayHasKey('provider', $byName);
        self::assertFalse($byName['provider']['required']);
        self::assertSame(['mock', 'entsoe'], $byName['provider']['schema']['enum']);
    }

    public function test_validation_error_response_schema_documents_message_and_optional_errors_map(): void
    {
        $schema = self::spec()['components']['schemas']['ValidationErrorResponse'];

        self::assertSame('string', $schema['properties']['message']['type']);
        self::assertSame('object', $schema['properties']['errors']['type']);
        self::assertSame('array', $schema['properties']['errors']['additionalProperties']['type']);
    }

    public function test_home_assistant_export_declares_a_503_for_a_missing_or_failing_webhook(): void
    {
        $responses = self::spec()['paths']['/api/plans/{id}/export/home-assistant']['post']['responses'];

        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertArrayHasKey('503', $responses);
    }

    public function test_battery_input_percentages_are_bounded_between_zero_and_a_hundred(): void
    {
        $properties = self::spec()['components']['schemas']['BatteryInput']['properties'];

        foreach (['soc_min_percent', 'soc_max_percent', 'initial_soc_percent'] as $field) {
            self::assertSame(0, $properties[$field]['minimum']);
            self::assertSame(100, $properties[$field]['maximum']);
        }
    }

    public function test_every_ref_in_the_document_resolves_to_an_existing_node(): void
    {
        $spec = self::spec();

        $refs = [];
        self::collectRefs($spec, $refs);

        self::assertNotEmpty($refs, 'expected the spec to actually use $ref at least once');

        foreach (array_unique($refs) as $ref) {
            self::resolveRef($spec, $ref);
        }
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $refs
     */
    private static function collectRefs(array $node, array &$refs): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;

                continue;
            }

            if (is_array($value)) {
                self::collectRefs($value, $refs);
            }
        }
    }

    private static function resolveRef(array $document, string $ref): void
    {
        self::assertStringStartsWith('#/', $ref, "only local refs are supported, got '{$ref}'");

        $node = $document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            self::assertIsArray($node, "broken \$ref '{$ref}': '{$segment}' is not traversable");
            self::assertArrayHasKey($segment, $node, "broken \$ref '{$ref}': missing segment '{$segment}'");
            $node = $node[$segment];
        }
    }
}