<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The README claims docs/openapi.yaml "is verified against the actual Form
 * Requests and Resources, not written from memory". This test turns that
 * claim into an enforced regression check: every method+path registered
 * under api/* must be documented, and every method+path documented in the
 * spec must correspond to an actually registered route, so the two cannot
 * silently drift apart.
 */
final class OpenApiSpecMatchesRoutesTest extends TestCase
{
    public function test_the_spec_and_the_router_agree_on_the_exact_set_of_operations(): void
    {
        self::assertSame($this->registeredApiOperations(), $this->documentedOperations());
    }

    public function test_every_registered_api_route_is_documented(): void
    {
        $documented = $this->documentedOperations();

        foreach ($this->registeredApiOperations() as $operation) {
            self::assertContains($operation, $documented, "route '{$operation}' is registered but not documented in docs/openapi.yaml");
        }
    }

    public function test_every_documented_operation_matches_a_registered_route(): void
    {
        $registered = $this->registeredApiOperations();

        foreach ($this->documentedOperations() as $operation) {
            self::assertContains($operation, $registered, "docs/openapi.yaml documents '{$operation}', but no such route is registered");
        }
    }

    /**
     * @return list<string> e.g. ["GET /api/prices", "POST /api/simulate"]
     */
    private function registeredApiOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (!str_starts_with($uri, '/api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $operations[] = "{$method} {$uri}";
            }
        }

        return $this->sortedUnique($operations);
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));

        $operations = [];

        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtoupper((string) $method).' '.$path;
            }
        }

        return $this->sortedUnique($operations);
    }

    /**
     * @param list<string> $operations
     * @return list<string>
     */
    private function sortedUnique(array $operations): array
    {
        $operations = array_values(array_unique($operations));
        sort($operations);

        return $operations;
    }
}