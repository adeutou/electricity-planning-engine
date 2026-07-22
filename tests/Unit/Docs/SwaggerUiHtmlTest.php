<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * docs/index.html is a static, build-step-free Swagger UI page loaded via
 * CDN. These tests check that the page still points at the right elements
 * (title, mount point, CDN assets, banner links) and that the inline
 * SwaggerUIBundle configuration still loads docs/openapi.yaml as a relative
 * URL, which is what makes this page work unmodified once published under
 * a GitHub Pages project subpath.
 */
final class SwaggerUiHtmlTest extends TestCase
{
    private const HTML_PATH = __DIR__.'/../../../docs/index.html';

    private static string $html;
    private static DOMXPath $xpath;

    public static function setUpBeforeClass(): void
    {
        self::$html = file_get_contents(self::HTML_PATH);

        $dom = new DOMDocument();

        $previousSetting = libxml_use_internal_errors(true);
        $dom->loadHTML(self::$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        self::$xpath = new DOMXPath($dom);
    }

    public function test_the_file_exists(): void
    {
        self::assertFileExists(self::HTML_PATH);
    }

    public function test_the_page_title_identifies_the_api(): void
    {
        $title = self::$xpath->query('//title')->item(0);

        self::assertNotNull($title);
        self::assertSame('Electricity Planning Engine: API Reference', $title->textContent);
    }

    public function test_the_meta_description_mentions_the_project_and_laravel(): void
    {
        $description = self::$xpath->query('//meta[@name="description"]')->item(0);

        self::assertNotNull($description);
        self::assertStringContainsString('Electricity Planning Engine', $description->getAttribute('content'));
        self::assertStringContainsString('Laravel', $description->getAttribute('content'));
    }

    public function test_the_viewport_meta_tag_allows_pinch_zoom_up_to_5x(): void
    {
        $viewport = self::$xpath->query('//meta[@name="viewport"]')->item(0);

        self::assertNotNull($viewport);
        self::assertStringContainsString('width=device-width', $viewport->getAttribute('content'));
        self::assertStringContainsString('maximum-scale=5', $viewport->getAttribute('content'));
    }

    public function test_swagger_ui_css_is_loaded_from_the_pinned_major_version_5_cdn(): void
    {
        $stylesheet = self::$xpath->query('//link[@rel="stylesheet"]')->item(0);

        self::assertNotNull($stylesheet);
        self::assertSame('https://unpkg.com/swagger-ui-dist@5/swagger-ui.css', $stylesheet->getAttribute('href'));
    }

    public function test_swagger_ui_bundle_and_standalone_preset_scripts_are_loaded_from_the_same_cdn_version(): void
    {
        $scripts = [];
        foreach (self::$xpath->query('//script[@src]') as $script) {
            $scripts[] = $script->getAttribute('src');
        }

        self::assertContains('https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js', $scripts);
        self::assertContains('https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js', $scripts);
    }

    public function test_there_is_exactly_one_swagger_ui_mount_point(): void
    {
        $mount = self::$xpath->query('//div[@id="swagger-ui"]');

        self::assertCount(1, $mount);
    }

    public function test_the_banner_links_to_the_repository_readme_and_home_assistant_doc(): void
    {
        $hrefs = [];
        foreach (self::$xpath->query('//div[@class="banner"]//a') as $link) {
            $hrefs[] = $link->getAttribute('href');
        }

        self::assertContains('https://github.com/adeutou/electricity-planning-engine', $hrefs);
        self::assertContains('https://github.com/adeutou/electricity-planning-engine#readme', $hrefs);
        self::assertContains(
            'https://github.com/adeutou/electricity-planning-engine/blob/main/docs/home-assistant-integration.md',
            $hrefs
        );
    }

    public function test_swagger_ui_is_initialised_against_the_relative_openapi_yaml_spec(): void
    {
        self::assertStringContainsString('url: "openapi.yaml"', self::$html);
        self::assertStringContainsString('dom_id: "#swagger-ui"', self::$html);
        self::assertStringContainsString('layout: "StandaloneLayout"', self::$html);
        self::assertStringContainsString('deepLinking: true', self::$html);
    }

    public function test_the_openapi_url_is_relative_not_absolute(): void
    {
        // A relative "openapi.yaml" is what makes this page work whether it is
        // served at the domain root or under a GitHub Pages project subpath;
        // an absolute URL here would break the second case.
        self::assertMatchesRegularExpression('/url:\s*"openapi\.yaml"/', self::$html);
        self::assertDoesNotMatchRegularExpression('/url:\s*"https?:\/\//', self::$html);
    }

    public function test_the_default_swagger_topbar_is_hidden_since_a_custom_banner_replaces_it(): void
    {
        self::assertStringContainsString('.topbar { display: none; }', self::$html);
    }

    public function test_swagger_ui_apis_and_standalone_presets_are_both_registered(): void
    {
        self::assertStringContainsString('SwaggerUIBundle.presets.apis', self::$html);
        self::assertStringContainsString('SwaggerUIStandalonePreset', self::$html);
    }
}