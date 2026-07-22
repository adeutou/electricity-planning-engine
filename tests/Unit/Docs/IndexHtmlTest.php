<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * docs/index.html is a static Swagger UI shell published as-is to GitHub
 * Pages (see .github/workflows/pages.yml): no build step, no templating.
 * These tests pin down the pieces the PR relies on: the CDN assets it
 * loads, that it points at the relative docs/openapi.yaml, and the mobile
 * responsiveness tweaks called out in the "responsiv for the github-pages"
 * commit.
 */
final class IndexHtmlTest extends TestCase
{
    private static ?string $html = null;

    private static function html(): string
    {
        if (self::$html === null) {
            self::$html = file_get_contents(self::path());
        }

        return self::$html;
    }

    private static function path(): string
    {
        return __DIR__.'/../../../docs/index.html';
    }

    public function test_the_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::path());
        self::assertFileIsReadable(self::path());
    }

    public function test_it_is_well_formed_html_without_fatal_parse_errors(): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument();
        $loaded = $document->loadHTML(self::html());

        $fatalErrors = array_values(array_filter(
            libxml_get_errors(),
            static fn (\LibXMLError $error): bool => $error->level === LIBXML_ERR_FATAL
        ));

        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'DOMDocument failed to load docs/index.html');
        self::assertSame([], $fatalErrors, 'docs/index.html has fatal libxml parse errors');
    }

    public function test_the_title_tag_names_the_project(): void
    {
        self::assertMatchesRegularExpression(
            '#<title>Electricity Planning Engine: API Reference</title>#',
            self::html()
        );
    }

    public function test_the_viewport_meta_tag_allows_pinch_zoom_on_mobile(): void
    {
        self::assertMatchesRegularExpression(
            '/<meta\s+name="viewport"\s+content="width=device-width, initial-scale=1, maximum-scale=5"/',
            self::html()
        );
    }

    public function test_it_points_swagger_ui_at_the_relative_openapi_yaml_file(): void
    {
        self::assertStringContainsString('url: "openapi.yaml"', self::html());
    }

    public function test_it_loads_swagger_ui_dist_assets_from_the_unpkg_cdn(): void
    {
        $html = self::html();

        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui.css', $html);
        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js', $html);
        self::assertStringContainsString('https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js', $html);
    }

    public function test_it_contains_a_mount_point_for_swagger_ui(): void
    {
        self::assertStringContainsString('<div id="swagger-ui"></div>', self::html());
    }

    public function test_it_initializes_swagger_ui_bundle_with_the_standalone_layout(): void
    {
        $html = self::html();

        self::assertStringContainsString('dom_id: "#swagger-ui"', $html);
        self::assertStringContainsString('deepLinking: true', $html);
        self::assertStringContainsString('layout: "StandaloneLayout"', $html);
        self::assertMatchesRegularExpression(
            '/presets:\s*\[SwaggerUIBundle\.presets\.apis,\s*SwaggerUIStandalonePreset\]/',
            $html
        );
    }

    public function test_the_default_swagger_topbar_is_hidden_in_favor_of_the_custom_banner(): void
    {
        self::assertStringContainsString('.topbar { display: none; }', self::html());
    }

    public function test_the_banner_links_to_the_github_repository_and_readme(): void
    {
        $html = self::html();

        self::assertStringContainsString('https://github.com/adeutou/electricity-planning-engine"', $html);
        self::assertStringContainsString('https://github.com/adeutou/electricity-planning-engine#readme', $html);
        self::assertStringContainsString(
            'https://github.com/adeutou/electricity-planning-engine/blob/main/docs/home-assistant-integration.md',
            $html
        );
    }

    public function test_swagger_ui_tables_are_allowed_to_scroll_horizontally_instead_of_overflowing(): void
    {
        self::assertMatchesRegularExpression(
            '/\.swagger-ui table \{\s*display: block;\s*max-width: 100%;\s*overflow-x: auto;\s*\}/',
            self::html()
        );
    }

    public function test_touch_targets_are_enlarged_under_the_mobile_breakpoint(): void
    {
        self::assertStringContainsString('@media (max-width: 640px)', self::html());
        self::assertStringContainsString('min-height: 40px;', self::html());
    }
}