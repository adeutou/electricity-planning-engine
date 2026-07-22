<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/workflows/pages.yml publishes docs/ to GitHub Pages. These tests
 * pin down its triggers, permissions and steps so that a future edit can't
 * silently widen the trigger (e.g. firing on every push to main instead of
 * only docs/ changes), loosen the minimal permission set, or reorder/drop a
 * deployment step without a test failing.
 */
final class PagesWorkflowTest extends TestCase
{
    private const WORKFLOW_PATH = __DIR__.'/../../../.github/workflows/pages.yml';

    /** @var array<string, mixed> */
    private static array $workflow;

    public static function setUpBeforeClass(): void
    {
        self::$workflow = Yaml::parseFile(self::WORKFLOW_PATH);
    }

    public function test_the_workflow_file_exists_and_parses_as_valid_yaml(): void
    {
        self::assertFileExists(self::WORKFLOW_PATH);
        self::assertIsArray(self::$workflow);
    }

    public function test_workflow_name(): void
    {
        self::assertSame('Deploy API docs', self::$workflow['name']);
    }

    public function test_it_triggers_on_push_to_main_scoped_to_docs_and_workflow_paths(): void
    {
        $triggers = self::$workflow['on'];

        self::assertArrayHasKey('push', $triggers);
        self::assertSame(['main'], $triggers['push']['branches']);
        self::assertSame(['docs/**', '.github/workflows/pages.yml'], $triggers['push']['paths']);
    }

    public function test_it_can_also_be_triggered_manually(): void
    {
        self::assertArrayHasKey('workflow_dispatch', self::$workflow['on']);
    }

    public function test_permissions_are_scoped_to_the_minimum_required_for_pages_deployment(): void
    {
        self::assertSame([
            'contents' => 'read',
            'pages' => 'write',
            'id-token' => 'write',
        ], self::$workflow['permissions']);
    }

    public function test_concurrency_group_prevents_overlapping_deployments_without_cancelling_an_in_flight_one(): void
    {
        self::assertSame('pages', self::$workflow['concurrency']['group']);
        self::assertFalse(self::$workflow['concurrency']['cancel-in-progress']);
    }

    public function test_deploy_job_runs_on_ubuntu_and_targets_the_github_pages_environment(): void
    {
        $job = self::$workflow['jobs']['deploy'];

        self::assertSame('ubuntu-latest', $job['runs-on']);
        self::assertSame('github-pages', $job['environment']['name']);
        self::assertSame('${{ steps.deployment.outputs.page_url }}', $job['environment']['url']);
    }

    public function test_deploy_job_has_exactly_four_steps_in_the_expected_order(): void
    {
        $steps = self::$workflow['jobs']['deploy']['steps'];

        self::assertCount(4, $steps);

        self::assertSame('Checkout', $steps[0]['name']);
        self::assertSame('actions/checkout@v4', $steps[0]['uses']);

        self::assertSame('Configure GitHub Pages', $steps[1]['name']);
        self::assertSame('actions/configure-pages@v5', $steps[1]['uses']);

        self::assertSame('Upload docs/ as Pages artifact', $steps[2]['name']);
        self::assertSame('actions/upload-pages-artifact@v3', $steps[2]['uses']);

        self::assertSame('Deploy to GitHub Pages', $steps[3]['name']);
        self::assertSame('actions/deploy-pages@v4', $steps[3]['uses']);
    }

    public function test_the_upload_step_publishes_the_docs_directory_as_is(): void
    {
        $uploadStep = self::$workflow['jobs']['deploy']['steps'][2];

        self::assertSame('docs', $uploadStep['with']['path']);
    }

    public function test_the_deploy_step_declares_the_id_referenced_by_the_environment_url(): void
    {
        $deployStep = self::$workflow['jobs']['deploy']['steps'][3];

        self::assertSame('deployment', $deployStep['id']);
    }
}