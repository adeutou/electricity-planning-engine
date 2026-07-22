<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * README.md's API section now announces the published Swagger UI reference
 * and the files behind it. These tests protect that paragraph (and its
 * cross-links to docs/openapi.yaml, docs/index.html and the Pages workflow)
 * from silently rotting if those files get renamed or moved.
 */
final class ReadmeApiDocsSectionTest extends TestCase
{
    private const README_PATH = __DIR__.'/../../../README.md';

    private static string $readme;

    public static function setUpBeforeClass(): void
    {
        self::$readme = file_get_contents(self::README_PATH);
    }

    public function test_the_readme_file_exists(): void
    {
        self::assertFileExists(self::README_PATH);
    }

    public function test_the_api_section_links_to_the_published_github_pages_swagger_ui(): void
    {
        self::assertStringContainsString(
            '[adeutou.github.io/electricity-planning-engine](https://adeutou.github.io/electricity-planning-engine/)',
            self::$readme
        );
    }

    public function test_the_api_section_links_to_the_openapi_spec_file(): void
    {
        self::assertStringContainsString('[`docs/openapi.yaml`](docs/openapi.yaml)', self::$readme);
    }

    public function test_the_api_section_links_to_the_swagger_ui_html_page(): void
    {
        self::assertStringContainsString('[`docs/index.html`](docs/index.html)', self::$readme);
    }

    public function test_the_api_section_links_to_the_pages_deployment_workflow(): void
    {
        self::assertStringContainsString('[`.github/workflows/pages.yml`](.github/workflows/pages.yml)', self::$readme);
    }

    public function test_the_api_section_mentions_swagger_ui_and_the_try_it_out_feature(): void
    {
        $apiSectionStart = strpos(self::$readme, '## API');
        self::assertNotFalse($apiSectionStart);

        $apiSection = substr(self::$readme, $apiSectionStart, 1000);

        self::assertStringContainsString('Swagger UI', $apiSection);
        self::assertStringContainsString('"Try it out"', $apiSection);
        self::assertStringContainsString('OpenAPI 3.0', $apiSection);
    }

    public function test_the_api_section_clarifies_the_spec_is_verified_against_the_real_code_not_written_from_memory(): void
    {
        self::assertStringContainsString(
            'verified against the actual Form Requests and Resources, not written from memory',
            self::$readme
        );
    }

    public function test_the_docs_paragraph_appears_before_the_endpoint_summary_table(): void
    {
        $paragraphPosition = strpos(self::$readme, 'Full interactive reference');
        $tablePosition = strpos(self::$readme, '| Method | Endpoint | Purpose |');

        self::assertNotFalse($paragraphPosition);
        self::assertNotFalse($tablePosition);
        self::assertLessThan($tablePosition, $paragraphPosition);
    }

    public function test_the_docs_paragraph_lives_directly_under_the_api_heading(): void
    {
        $apiHeadingPosition = strpos(self::$readme, "## API\n");
        $paragraphPosition = strpos(self::$readme, 'Full interactive reference');

        self::assertNotFalse($apiHeadingPosition);
        self::assertNotFalse($paragraphPosition);

        $between = substr(self::$readme, $apiHeadingPosition, $paragraphPosition - $apiHeadingPosition);

        // Only whitespace (blank line) should separate the heading from the
        // new paragraph: it must not have been appended somewhere else in
        // the API section by mistake.
        self::assertSame('', trim($between, "\n# API"));
    }
}