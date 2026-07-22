<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * docs/index.html is a static, no-build-step Swagger UI shell published as-is
 * to GitHub Pages (see .github/workflows/pages.yml). These tests guard the
 * few things that would silently break the published docs: the relative
 * pointer to docs/openapi.yaml, the dom_id/mount element pairing, and the
 * CDN script/style tags Swagger UI needs to render at all.
 */
final class DocsIndexHtmlTest extends TestCase
{
    private function html(): string
    {
        return file_get_contents(base_path('docs/index.html'));
    }

    private function document(): DOMDocument
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($this->html());
        libxml_use_internal_errors(false);

        return $document;
    }

    public function test_the_file_parses_as_html_without_fatal_errors(): void
    {
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML($this->html());
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        self::assertTrue($loaded);

        $fatalErrors = array_filter($errors, static fn ($error) => $error->level === LIBXML_ERR_FATAL);
        self::assertCount(0, $fatalErrors);
    }

    public function test_it_declares_the_expected_title_and_charset(): void
    {
        $document = $this->document();
        $xpath = new DOMXPath($document);

        $title = $xpath->query('//title')->item(0);
        self::assertNotNull($title);
        self::assertSame('Electricity Planning Engine: API Reference', $title->textContent);

        $charset = $xpath->query('//meta[@charset]')->item(0);
        self::assertNotNull($charset);
        self::assertSame('UTF-8', $charset->getAttribute('charset'));
    }

    public function test_it_has_a_responsive_viewport_meta_tag(): void
    {
        $document = $this->document();
        $xpath = new DOMXPath($document);

        $viewport = $xpath->query('//meta[@name="viewport"]')->item(0);

        self::assertNotNull($viewport);
        self::assertStringContainsString('width=device-width', $viewport->getAttribute('content'));
        self::assertStringContainsString('initial-scale=1', $viewport->getAttribute('content'));
    }

    public function test_it_loads_swagger_ui_css_and_scripts_from_the_same_cdn_version(): void
    {
        $html = $this->html();

        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui.css', $html);
        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js', $html);
        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js', $html);
    }

    public function test_the_mount_point_id_matches_the_dom_id_passed_to_swagger_ui_bundle(): void
    {
        $document = $this->document();
        $xpath = new DOMXPath($document);

        $mountPoint = $xpath->query('//div[@id="swagger-ui"]')->item(0);
        self::assertNotNull($mountPoint, 'expected a <div id="swagger-ui"> mount point');

        self::assertMatchesRegularExpression('/dom_id:\s*"#swagger-ui"/', $this->html());
    }

    public function test_swagger_ui_bundle_is_configured_to_load_the_spec_from_a_relative_path_that_exists_on_disk(): void
    {
        self::assertMatchesRegularExpression('/url:\s*"openapi\.yaml"/', $this->html());

        // "openapi.yaml" is resolved relative to docs/index.html once both are
        // published side by side by the Pages workflow.
        self::assertFileExists(base_path('docs/openapi.yaml'));
    }

    public function test_it_links_to_the_github_repository_readme_and_home_assistant_doc(): void
    {
        $document = $this->document();
        $xpath = new DOMXPath($document);

        $hrefs = [];
        foreach ($xpath->query('//div[@class="banner"]//a') as $anchor) {
            $hrefs[] = $anchor->getAttribute('href');
        }

        self::assertContains('https://github.com/adeutou/electricity-planning-engine', $hrefs);
        self::assertContains('https://github.com/adeutou/electricity-planning-engine#readme', $hrefs);
        self::assertContains(
            'https://github.com/adeutou/electricity-planning-engine/blob/main/docs/home-assistant-integration.md',
            $hrefs
        );

        // The banner links to this file by its published GitHub blob path;
        // make sure the file it points to actually still exists locally.
        self::assertFileExists(base_path('docs/home-assistant-integration.md'));
    }

    public function test_the_swagger_ui_topbar_is_disabled_since_the_page_already_has_a_custom_banner(): void
    {
        self::assertStringContainsString('.topbar { display: none; }', $this->html());
    }
}