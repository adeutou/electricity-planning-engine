<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * .github/workflows/pages.yml publishes docs/ to GitHub Pages as-is (no
 * build step). These tests pin down the trigger conditions (only republish
 * when docs/ or the workflow itself changes), the permissions Pages
 * deployment needs, and the step order, so a well-meaning edit doesn't
 * silently turn it into "deploy on every push" or drop a required permission.
 */
final class PagesWorkflowTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(base_path('.github/workflows/pages.yml'));

        return $parsed;
    }

    public function test_the_file_is_valid_yaml_with_the_expected_name(): void
    {
        $workflow = $this->workflow();

        self::assertSame('Deploy API docs', $workflow['name']);
    }

    public function test_it_only_triggers_on_pushes_to_main_touching_docs_or_the_workflow_itself(): void
    {
        // YAML parses the bare key `on:` as boolean true, so index by the
        // parsed boolean key rather than the string 'on'.
        $workflow = $this->workflow();
        $on = $workflow[true] ?? $workflow['on'];

        self::assertSame(['main'], $on['push']['branches']);
        self::assertSame(['docs/**', '.github/workflows/pages.yml'], $on['push']['paths']);
        self::assertArrayHasKey('workflow_dispatch', $on);
    }

    public function test_it_does_not_trigger_on_every_push_to_main(): void
    {
        $workflow = $this->workflow();
        $on = $workflow[true] ?? $workflow['on'];

        self::assertNotEmpty(
            $on['push']['paths'],
            'a push trigger with no paths filter would republish Pages on every commit to main'
        );
    }

    public function test_it_grants_exactly_the_permissions_pages_deployment_needs(): void
    {
        $workflow = $this->workflow();

        self::assertSame(
            [
                'contents' => 'read',
                'pages' => 'write',
                'id-token' => 'write',
            ],
            $workflow['permissions']
        );
    }

    public function test_concurrent_deployments_are_serialized_and_never_cancelled_mid_flight(): void
    {
        $workflow = $this->workflow();

        self::assertSame('pages', $workflow['concurrency']['group']);
        self::assertFalse($workflow['concurrency']['cancel-in-progress']);
    }

    public function test_the_deploy_job_targets_the_github_pages_environment(): void
    {
        $job = $this->workflow()['jobs']['deploy'];

        self::assertSame('ubuntu-latest', $job['runs-on']);
        self::assertSame('github-pages', $job['environment']['name']);
        self::assertSame('${{ steps.deployment.outputs.page_url }}', $job['environment']['url']);
    }

    public function test_the_deploy_job_runs_the_expected_steps_in_order(): void
    {
        $steps = $this->workflow()['jobs']['deploy']['steps'];

        $usedActions = array_map(static fn (array $step) => $step['uses'] ?? null, $steps);

        self::assertSame(
            [
                'actions/checkout@v4',
                'actions/configure-pages@v5',
                'actions/upload-pages-artifact@v3',
                'actions/deploy-pages@v4',
            ],
            $usedActions
        );
    }

    public function test_the_upload_step_publishes_the_docs_directory_verbatim(): void
    {
        $steps = $this->workflow()['jobs']['deploy']['steps'];

        $uploadStep = current(array_filter(
            $steps,
            static fn (array $step) => ($step['uses'] ?? null) === 'actions/upload-pages-artifact@v3'
        ));

        self::assertNotFalse($uploadStep);
        self::assertSame('docs', $uploadStep['with']['path']);
    }

    public function test_the_deploy_step_has_the_id_referenced_by_the_environment_url(): void
    {
        $steps = $this->workflow()['jobs']['deploy']['steps'];

        $deployStep = current(array_filter(
            $steps,
            static fn (array $step) => ($step['uses'] ?? null) === 'actions/deploy-pages@v4'
        ));

        self::assertNotFalse($deployStep);
        self::assertSame('deployment', $deployStep['id']);
    }
}