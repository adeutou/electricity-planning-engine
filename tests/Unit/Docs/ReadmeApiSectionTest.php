<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * README.md gained an "API" paragraph pointing readers at the published
 * Swagger UI and the files behind it (docs/openapi.yaml, docs/index.html,
 * .github/workflows/pages.yml). These tests keep that paragraph honest:
 * every link it makes must point at a path that actually exists in the
 * repository.
 */
final class ReadmeApiSectionTest extends TestCase
{
    private static ?string $readme = null;

    private static function readme(): string
    {
        if (self::$readme === null) {
            self::$readme = file_get_contents(self::path());
        }

        return self::$readme;
    }

    private static function path(): string
    {
        return __DIR__.'/../../../README.md';
    }

    public function test_the_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::path());
        self::assertFileIsReadable(self::path());
    }

    public function test_the_api_section_links_to_the_published_swagger_ui_page(): void
    {
        self::assertStringContainsString(
            '[adeutou.github.io/electricity-planning-engine](https://adeutou.github.io/electricity-planning-engine/)',
            self::readme()
        );
    }

    public function test_the_api_section_links_to_files_that_exist_in_the_repository(): void
    {
        $readme = self::readme();
        $repositoryRoot = __DIR__.'/../../../';

        foreach (
            [
                '[`docs/openapi.yaml`](docs/openapi.yaml)' => 'docs/openapi.yaml',
                '[`docs/index.html`](docs/index.html)' => 'docs/index.html',
                '[`.github/workflows/pages.yml`](.github/workflows/pages.yml)' => '.github/workflows/pages.yml',
            ] as $markdownLink => $relativePath
        ) {
            self::assertStringContainsString($markdownLink, $readme);
            self::assertFileExists($repositoryRoot.$relativePath, "README links to {$relativePath}, which does not exist");
        }
    }

    public function test_the_api_section_explains_the_spec_is_verified_not_hand_written_from_memory(): void
    {
        self::assertStringContainsString(
            'is verified against the actual Form Requests and Resources, not written from memory',
            self::readme()
        );
    }

    public function test_the_api_section_explains_when_the_pages_workflow_republishes(): void
    {
        self::assertStringContainsString(
            'republishes it to GitHub Pages whenever `docs/` changes on `main`',
            self::readme()
        );
    }

    public function test_the_api_section_appears_before_the_endpoints_table(): void
    {
        $readme = self::readme();

        $sectionPosition = strpos($readme, 'Full interactive reference (OpenAPI 3.0 + Swagger UI');
        $tablePosition = strpos($readme, '| Method | Endpoint | Purpose |');

        self::assertNotFalse($sectionPosition);
        self::assertNotFalse($tablePosition);
        self::assertLessThan($tablePosition, $sectionPosition);
    }
}