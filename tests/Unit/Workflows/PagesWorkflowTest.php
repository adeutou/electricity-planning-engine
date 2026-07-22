<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/workflows/pages.yml publishes docs/ to GitHub Pages. These tests
 * pin down the trigger conditions (only on docs/ changes, not every push to
 * main), the least-privilege permissions, and the exact deploy steps, since
 * a silent regression here (e.g. a broadened path filter or a missing
 * permission) would either republish on every commit or break deployment
 * without any PHP test ever noticing.
 */
final class PagesWorkflowTest extends TestCase
{
    private static ?array $workflow = null;

    private static function workflow(): array
    {
        if (self::$workflow === null) {
            self::$workflow = Yaml::parseFile(self::path(), Yaml::PARSE_KEYS_AS_STRINGS);
        }

        return self::$workflow;
    }

    private static function path(): string
    {
        return __DIR__.'/../../../.github/workflows/pages.yml';
    }

    public function test_the_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::path());
        self::assertFileIsReadable(self::path());
    }

    public function test_it_parses_as_valid_yaml(): void
    {
        self::assertIsArray(self::workflow());
    }

    public function test_the_workflow_is_named_deploy_api_docs(): void
    {
        self::assertSame('Deploy API docs', self::workflow()['name']);
    }

    public function test_it_only_triggers_on_push_to_main_when_docs_or_the_workflow_itself_change(): void
    {
        $push = self::workflow()['on']['push'];

        self::assertSame(['main'], $push['branches']);
        self::assertSame(['docs/**', '.github/workflows/pages.yml'], $push['paths']);
    }

    public function test_it_can_also_be_triggered_manually(): void
    {
        self::assertArrayHasKey('workflow_dispatch', self::workflow()['on']);
    }

    public function test_permissions_are_least_privilege_for_a_pages_deployment(): void
    {
        self::assertSame(
            [
                'contents' => 'read',
                'pages' => 'write',
                'id-token' => 'write',
            ],
            self::workflow()['permissions']
        );
    }

    public function test_concurrent_deployments_are_queued_rather_than_cancelled(): void
    {
        $concurrency = self::workflow()['concurrency'];

        self::assertSame('pages', $concurrency['group']);
        self::assertFalse($concurrency['cancel-in-progress']);
    }

    public function test_the_deploy_job_targets_the_github_pages_environment(): void
    {
        $job = self::workflow()['jobs']['deploy'];

        self::assertSame('ubuntu-latest', $job['runs-on']);
        self::assertSame('github-pages', $job['environment']['name']);
        self::assertSame('${{ steps.deployment.outputs.page_url }}', $job['environment']['url']);
    }

    public function test_the_deploy_job_checks_out_configures_and_deploys_pages_in_order(): void
    {
        $steps = self::workflow()['jobs']['deploy']['steps'];
        $uses = array_column($steps, 'uses');

        self::assertSame(
            [
                'actions/checkout@v4',
                'actions/configure-pages@v5',
                'actions/upload-pages-artifact@v3',
                'actions/deploy-pages@v4',
            ],
            $uses
        );
    }

    public function test_the_upload_artifact_step_publishes_the_docs_directory_verbatim(): void
    {
        $steps = self::workflow()['jobs']['deploy']['steps'];
        $uploadStep = current(array_filter($steps, static fn (array $step): bool => ($step['uses'] ?? null) === 'actions/upload-pages-artifact@v3'));

        self::assertNotFalse($uploadStep, 'expected an actions/upload-pages-artifact@v3 step');
        self::assertSame('docs', $uploadStep['with']['path']);
    }

    public function test_the_deploy_step_exposes_a_deployment_id_used_by_the_environment_url(): void
    {
        $steps = self::workflow()['jobs']['deploy']['steps'];
        $deployStep = current(array_filter($steps, static fn (array $step): bool => ($step['uses'] ?? null) === 'actions/deploy-pages@v4'));

        self::assertNotFalse($deployStep, 'expected an actions/deploy-pages@v4 step');
        self::assertSame('deployment', $deployStep['id']);
    }
}