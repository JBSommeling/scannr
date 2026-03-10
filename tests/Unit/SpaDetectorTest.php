<?php

namespace Tests\Unit;

use App\Services\SpaDetector;
use Tests\TestCase;

class SpaDetectorTest extends TestCase
{
    private SpaDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new SpaDetector;
    }

    // ==================
    // detect() — no links
    // ==================

    public function test_detects_no_navigable_links(): void
    {
        $html = '<html><body><div id="root"></div></body></html>';

        $result = $this->detector->detect($html, []);
        $this->assertTrue($result['detected']);
        $this->assertEquals('no navigable links found', $result['reason']);
    }

    public function test_detects_no_anchor_links_among_other_elements(): void
    {
        $links = [
            ['url' => '/style.css', 'element' => 'link', 'source' => 'test'],
            ['url' => '/app.js', 'element' => 'script', 'source' => 'test'],
        ];

        $result = $this->detector->detect('<html><body>content</body></html>', $links);
        $this->assertTrue($result['detected']);
        $this->assertEquals('no navigable links found', $result['reason']);
    }

    // ==================
    // detect() — empty body
    // ==================

    public function test_detects_null_body(): void
    {
        // No anchor links → triggers "no navigable links" first
        $result = $this->detector->detect(null, []);
        $this->assertTrue($result['detected']);
        $this->assertEquals('no navigable links found', $result['reason']);
    }

    public function test_detects_empty_string_body(): void
    {
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect('', $links);
        $this->assertTrue($result['detected']);
        $this->assertEquals('empty response body', $result['reason']);
    }

    public function test_detects_whitespace_only_body(): void
    {
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect('   ', $links);
        $this->assertTrue($result['detected']);
        $this->assertEquals('empty response body', $result['reason']);
    }

    public function test_detects_empty_dom_body_with_mount_point(): void
    {
        $html = '<html><body><div id="root"></div><script src="/app.js"></script></body></html>';
        $links = [['url' => '/about', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('empty DOM body', $result['reason']);
    }

    // ==================
    // detect() — framework markers
    // ==================

    public function test_detects_nextjs_via_next_data(): void
    {
        $html = '<html><body><div>Some real content here with enough text to pass the empty check threshold</div><script id="__NEXT_DATA__" type="application/json">{}</script></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Next.js', $result['reason']);
    }

    public function test_detects_nextjs_via_next_path(): void
    {
        $html = '<html><body><div>Some real content here with enough text to pass the empty check threshold</div><script src="/_next/static/chunks/main.js"></script></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Next.js', $result['reason']);
    }

    public function test_detects_nuxtjs_via_nuxt_global(): void
    {
        $html = '<html><body><div>Content with enough text to not be considered empty body content</div><script>window.__NUXT__={}</script></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Nuxt.js', $result['reason']);
    }

    public function test_detects_nuxtjs_via_nuxt_path(): void
    {
        $html = '<html><body><div>Content with enough text to not be considered empty body content</div><script src="/_nuxt/entry.js"></script></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Nuxt.js', $result['reason']);
    }

    public function test_detects_angular_via_ng_version(): void
    {
        $html = '<html><body><app-root ng-version="16.2.0">Loading the application, please wait while we initialize everything for you.</app-root></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Angular', $result['reason']);
    }

    public function test_detects_angular_via_empty_app_root(): void
    {
        $html = '<html><body><div>Some content that is long enough to pass the empty body threshold check easily</div><app-root></app-root></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Angular', $result['reason']);
    }

    public function test_detects_gatsby(): void
    {
        $html = '<html><body><div id="___gatsby"><div>Real rendered content with enough text to pass threshold</div></div></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('Gatsby', $result['reason']);
    }

    public function test_detects_server_rendered_hydration_marker(): void
    {
        $html = '<html><body><div data-server-rendered="true">Content with more than enough text here to ensure it is not considered empty by the detector</div></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('hydration marker', $result['reason']);
    }

    public function test_detects_react_via_data_reactroot(): void
    {
        $html = '<html><body><div data-reactroot="">Content with enough text here to not be considered empty</div></body></html>';
        $links = [['url' => '/page', 'element' => 'a', 'source' => 'test']];

        $result = $this->detector->detect($html, $links);
        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('React', $result['reason']);
    }

    // ==================
    // detect() — no detection on normal HTML
    // ==================

    public function test_does_not_detect_on_normal_html(): void
    {
        $html = '<html><body><h1>Welcome to our website</h1><p>We have lots of content here that a normal website would have, including navigation and article text.</p><nav><a href="/about">About</a><a href="/contact">Contact</a></nav></body></html>';
        $links = [
            ['url' => '/about', 'element' => 'a', 'source' => 'test'],
            ['url' => '/contact', 'element' => 'a', 'source' => 'test'],
        ];

        $result = $this->detector->detect($html, $links);
        $this->assertFalse($result['detected']);
        $this->assertEquals('', $result['reason']);
    }

    public function test_does_not_detect_on_wordpress_like_html(): void
    {
        $html = '<html><body><header><nav><a href="/">Home</a><a href="/blog">Blog</a></nav></header><main><article><h1>Blog Post Title</h1><p>This is a blog post with real content that should not trigger SPA detection at all.</p></article></main><footer><a href="/privacy">Privacy</a></footer></body></html>';
        $links = [
            ['url' => '/', 'element' => 'a', 'source' => 'test'],
            ['url' => '/blog', 'element' => 'a', 'source' => 'test'],
            ['url' => '/privacy', 'element' => 'a', 'source' => 'test'],
        ];

        $result = $this->detector->detect($html, $links);
        $this->assertFalse($result['detected']);
    }

    // ==================
    // isBodyEffectivelyEmpty()
    // ==================

    public function test_empty_mount_point_is_effectively_empty(): void
    {
        $this->assertTrue(
            $this->detector->isBodyEffectivelyEmpty('<html><body><div id="root"></div></body></html>')
        );
    }

    public function test_scripts_only_body_is_effectively_empty(): void
    {
        $this->assertTrue(
            $this->detector->isBodyEffectivelyEmpty('<html><body><script>var x=1;</script></body></html>')
        );
    }

    public function test_styles_only_body_is_effectively_empty(): void
    {
        $this->assertTrue(
            $this->detector->isBodyEffectivelyEmpty('<html><body><style>body{color:red}</style></body></html>')
        );
    }

    public function test_noscript_only_body_is_effectively_empty(): void
    {
        $this->assertTrue(
            $this->detector->isBodyEffectivelyEmpty('<html><body><noscript>Enable JavaScript</noscript></body></html>')
        );
    }

    public function test_real_content_is_not_effectively_empty(): void
    {
        $this->assertFalse(
            $this->detector->isBodyEffectivelyEmpty(
                '<html><body><h1>Welcome</h1><p>This is a real website with plenty of content that exceeds the threshold.</p></body></html>'
            )
        );
    }

    public function test_no_body_tag_returns_false(): void
    {
        $this->assertFalse(
            $this->detector->isBodyEffectivelyEmpty('<html><div>content</div></html>')
        );
    }

    public function test_mixed_empty_elements_is_effectively_empty(): void
    {
        $html = '<html><body><div id="app"></div><script src="/bundle.js"></script><style>.x{}</style><noscript>JS required</noscript></body></html>';
        $this->assertTrue($this->detector->isBodyEffectivelyEmpty($html));
    }

    // ==================
    // detectFrameworkMarkers()
    // ==================

    public function test_framework_marker_react_empty_root(): void
    {
        $html = '<html><head></head><body><div id="root"></div><script src="/static/js/bundle.js"></script></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertNotNull($result);
        $this->assertStringContainsString('React', $result);
    }

    public function test_framework_marker_vue_empty_app(): void
    {
        $html = '<html><head></head><body><div id="app"></div><script src="/js/app.js"></script></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertNotNull($result);
        $this->assertStringContainsString('Vue', $result);
    }

    public function test_framework_marker_returns_null_for_normal_html(): void
    {
        $html = '<html><body><h1>Hello</h1><p>Normal website content</p></body></html>';
        $this->assertNull($this->detector->detectFrameworkMarkers($html));
    }

    public function test_framework_marker_react_root_with_content_not_detected(): void
    {
        // A non-empty root div should NOT trigger React detection
        $html = '<html><body><div id="root"><h1>Rendered content</h1></div><script src="/app.js"></script></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        // The empty root regex won't match because the div has content
        $this->assertNull($result);
    }

    public function test_framework_marker_vue_app_with_content_not_detected(): void
    {
        // A non-empty app div should NOT trigger Vue detection
        $html = '<html><body><div id="app"><h1>Rendered content</h1></div><script src="/app.js"></script></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertNull($result);
    }

    public function test_framework_marker_nextjs_data_script(): void
    {
        $html = '<html><body><script id="__NEXT_DATA__" type="application/json">{"page":"/"}</script></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertStringContainsString('Next.js', $result);
    }

    public function test_framework_marker_gatsby_div(): void
    {
        $html = '<html><body><div id="___gatsby"></div></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertStringContainsString('Gatsby', $result);
    }

    public function test_framework_marker_angular_ng_version(): void
    {
        $html = '<html><body><app-root ng-version="17.0.0"></app-root></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertStringContainsString('Angular', $result);
    }

    public function test_framework_marker_angular_empty_app_root(): void
    {
        $html = '<html><body><app-root></app-root></body></html>';
        $result = $this->detector->detectFrameworkMarkers($html);
        $this->assertStringContainsString('Angular', $result);
    }
}
