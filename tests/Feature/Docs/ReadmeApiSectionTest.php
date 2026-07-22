<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * README.md's "## API" section links to the Pages-published Swagger UI and to
 * the three files that make it possible. These tests check that the
 * relative links actually resolve to files that exist in the repository,
 * so a rename of docs/openapi.yaml, docs/index.html or the workflow doesn't
 * silently leave a dead link in the README.
 */
final class ReadmeApiSectionTest extends TestCase
{
    private function readme(): string
    {
        return file_get_contents(base_path('README.md'));
    }

    public function test_the_api_section_links_to_the_published_github_pages_site(): void
    {
        self::assertStringContainsString(
            'https://adeutou.github.io/electricity-planning-engine/',
            $this->readme()
        );
    }

    public function test_the_api_section_links_to_the_openapi_spec_file_and_it_exists(): void
    {
        self::assertStringContainsString('[`docs/openapi.yaml`](docs/openapi.yaml)', $this->readme());
        self::assertFileExists(base_path('docs/openapi.yaml'));
    }

    public function test_the_api_section_links_to_the_swagger_ui_page_and_it_exists(): void
    {
        self::assertStringContainsString('[`docs/index.html`](docs/index.html)', $this->readme());
        self::assertFileExists(base_path('docs/index.html'));
    }

    public function test_the_api_section_links_to_the_pages_workflow_and_it_exists(): void
    {
        self::assertStringContainsString(
            '[`.github/workflows/pages.yml`](.github/workflows/pages.yml)',
            $this->readme()
        );
        self::assertFileExists(base_path('.github/workflows/pages.yml'));
    }

    public function test_the_api_section_appears_before_the_endpoint_reference_table(): void
    {
        $readme = $this->readme();

        $apiHeadingPosition = strpos($readme, '## API');
        $tablePosition = strpos($readme, '| Method | Endpoint | Purpose |');

        self::assertNotFalse($apiHeadingPosition);
        self::assertNotFalse($tablePosition);
        self::assertLessThan($tablePosition, $apiHeadingPosition);
    }
}