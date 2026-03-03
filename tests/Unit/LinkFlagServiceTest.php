<?php

namespace Tests\Unit;

use App\Enums\LinkFlag;
use App\Services\LinkFlagService;
use App\Services\SeverityEvaluator;
use App\Services\UrlNormalizer;
use Tests\TestCase;

class LinkFlagServiceTest extends TestCase
{
    private LinkFlagService $linkFlagService;
    private UrlNormalizer $urlNormalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer();
        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');
        $severityEvaluator = new SeverityEvaluator();
        $this->linkFlagService = new LinkFlagService($this->urlNormalizer, $severityEvaluator);
    }

    // ===================
    // detectFromUrl tests - malformed URL detection
    // ===================

    public function test_clean_subdomain_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://yoga-demo.sommeling.dev';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_clean_subdomain_url_with_path_is_not_flagged_as_malformed(): void
    {
        $url = 'https://app.sommeling.dev/dashboard';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_clean_external_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://www.example.com/page';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_query_params_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com/search?q=test&page=1';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_template_literal_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/user/${userId}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_url_with_ruby_interpolation_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/user/#{name}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_vue_interpolation_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/user/{userId}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_backtick_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/test`';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_trailing_comma_word_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/test,param';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_newline_is_flagged_as_malformed(): void
    {
        $url = "https://example.com/test\nmore";
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_encoded_braces_is_not_flagged_as_malformed(): void
    {
        // URL with encoded braces should be OK
        $url = 'https://example.com/path?filter=%7Bvalue%7D';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    // ===================
    // detectFromUrl tests - external platform detection
    // ===================

    public function test_linkedin_url_is_flagged_as_external_platform(): void
    {
        $url = 'https://www.linkedin.com/in/sommelingdev';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::EXTERNAL_PLATFORM, $flags);
    }

    public function test_github_url_is_flagged_as_external_platform(): void
    {
        $url = 'https://github.com/sommelingdev';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::EXTERNAL_PLATFORM, $flags);
    }

    public function test_internal_url_is_not_flagged_as_external_platform(): void
    {
        $url = 'https://www.sommeling.dev/about';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertNotContains(LinkFlag::EXTERNAL_PLATFORM, $flags);
    }

    // ===================
    // detectFromHttpResponse tests
    // ===================

    public function test_timeout_status_adds_timeout_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse('Timeout');

        $this->assertContains(LinkFlag::TIMEOUT, $flags);
    }

    public function test_error_status_adds_connection_error_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse('Error');

        $this->assertContains(LinkFlag::CONNECTION_ERROR, $flags);
    }

    public function test_403_status_adds_bot_protection_and_4xx_flags(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(403);

        $this->assertContains(LinkFlag::BOT_PROTECTION, $flags);
        $this->assertContains(LinkFlag::STATUS_4XX, $flags);
    }

    public function test_405_status_adds_bot_protection_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(405);

        $this->assertContains(LinkFlag::BOT_PROTECTION, $flags);
    }

    public function test_429_status_adds_rate_limited_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(429);

        $this->assertContains(LinkFlag::RATE_LIMITED, $flags);
    }

    public function test_404_status_adds_4xx_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(404);

        $this->assertContains(LinkFlag::STATUS_4XX, $flags);
        $this->assertNotContains(LinkFlag::BOT_PROTECTION, $flags);
    }

    public function test_500_status_adds_5xx_flag(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(500);

        $this->assertContains(LinkFlag::STATUS_5XX, $flags);
    }

    public function test_200_status_adds_no_flags(): void
    {
        $flags = $this->linkFlagService->detectFromHttpResponse(200);

        $this->assertEmpty($flags);
    }

    // ===================
    // detectFromDiscovery tests
    // ===================

    public function test_js_bundle_discovery_adds_detected_in_js_bundle_flag(): void
    {
        $flags = $this->linkFlagService->detectFromDiscovery(
            isJsRendered: false,
            fromJsBundle: true,
            hasSuspiciousSyntax: false
        );

        $this->assertContains(LinkFlag::DETECTED_IN_JS_BUNDLE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_js_rendered_discovery_adds_runtime_rendered_flag(): void
    {
        $flags = $this->linkFlagService->detectFromDiscovery(
            isJsRendered: true,
            fromJsBundle: false,
            hasSuspiciousSyntax: false
        );

        $this->assertContains(LinkFlag::RUNTIME_RENDERED, $flags);
    }

    public function test_static_html_discovery_adds_static_html_flag(): void
    {
        $flags = $this->linkFlagService->detectFromDiscovery(
            isJsRendered: false,
            fromJsBundle: false,
            hasSuspiciousSyntax: false
        );

        $this->assertContains(LinkFlag::STATIC_HTML, $flags);
    }

    public function test_suspicious_syntax_adds_malformed_and_indirect_flags(): void
    {
        $flags = $this->linkFlagService->detectFromDiscovery(
            isJsRendered: false,
            fromJsBundle: true,
            hasSuspiciousSyntax: true
        );

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    // ===================
    // detectFromRedirect tests
    // ===================

    public function test_redirect_chain_with_2_hops_adds_redirect_chain_flag(): void
    {
        $flags = $this->linkFlagService->detectFromRedirect(
            chain: ['https://a.com', 'https://b.com'],
            hasLoop: false,
            hasHttpsDowngrade: false
        );

        $this->assertContains(LinkFlag::REDIRECT_CHAIN, $flags);
        $this->assertNotContains(LinkFlag::EXCESSIVE_REDIRECTS, $flags);
    }

    public function test_redirect_chain_with_5_hops_adds_excessive_redirects_flag(): void
    {
        $flags = $this->linkFlagService->detectFromRedirect(
            chain: ['https://a.com', 'https://b.com', 'https://c.com', 'https://d.com', 'https://e.com'],
            hasLoop: false,
            hasHttpsDowngrade: false
        );

        $this->assertContains(LinkFlag::REDIRECT_CHAIN, $flags);
        $this->assertContains(LinkFlag::EXCESSIVE_REDIRECTS, $flags);
    }

    public function test_redirect_loop_adds_excessive_redirects_flag(): void
    {
        $flags = $this->linkFlagService->detectFromRedirect(
            chain: ['https://a.com', 'https://b.com'],
            hasLoop: true,
            hasHttpsDowngrade: false
        );

        $this->assertContains(LinkFlag::EXCESSIVE_REDIRECTS, $flags);
    }

    public function test_https_downgrade_adds_http_on_https_flag(): void
    {
        $flags = $this->linkFlagService->detectFromRedirect(
            chain: ['http://example.com'],
            hasLoop: false,
            hasHttpsDowngrade: true
        );

        $this->assertContains(LinkFlag::HTTP_ON_HTTPS, $flags);
    }

    public function test_single_redirect_does_not_add_chain_flag(): void
    {
        $flags = $this->linkFlagService->detectFromRedirect(
            chain: ['https://example.com/final'],
            hasLoop: false,
            hasHttpsDowngrade: false
        );

        $this->assertNotContains(LinkFlag::REDIRECT_CHAIN, $flags);
    }

    // ===================
    // shouldClearForSubdomain tests
    // ===================

    public function test_should_clear_for_subdomain_with_200_status(): void
    {
        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $result = $this->linkFlagService->shouldClearForSubdomain('https://yoga-demo.sommeling.dev', 200);

        $this->assertTrue($result);
    }

    public function test_should_not_clear_for_subdomain_with_non_200_status(): void
    {
        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $result = $this->linkFlagService->shouldClearForSubdomain('https://yoga-demo.sommeling.dev', 404);

        $this->assertFalse($result);
    }

    public function test_should_not_clear_for_non_subdomain_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $result = $this->linkFlagService->shouldClearForSubdomain('https://www.sommeling.dev/page', 200);

        $this->assertFalse($result);
    }

    // ===================
    // buildAnalysis tests
    // ===================

    public function test_build_analysis_returns_link_analysis_with_flags(): void
    {
        $flags = [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::EXTERNAL_PLATFORM];

        $analysis = $this->linkFlagService->buildAnalysis($flags, 200, true);

        $this->assertEquals(['detected_in_js_bundle', 'external_platform'], $analysis->toArray()['flags']);
    }

    public function test_build_analysis_deduplicates_flags(): void
    {
        $flags = [LinkFlag::STATUS_4XX, LinkFlag::STATUS_4XX, LinkFlag::BOT_PROTECTION];

        $analysis = $this->linkFlagService->buildAnalysis($flags, 403, true);

        $flagsArray = $analysis->toArray()['flags'];
        $this->assertCount(2, $flagsArray);
        $this->assertContains('status_4xx', $flagsArray);
        $this->assertContains('bot_protection', $flagsArray);
    }

    // ===================
    // isLoopbackUrl tests
    // ===================

    public function test_localhost_is_loopback(): void
    {
        $this->assertTrue($this->linkFlagService->isLoopbackUrl('http://localhost/api'));
    }

    public function test_127_0_0_1_is_loopback(): void
    {
        $this->assertTrue($this->linkFlagService->isLoopbackUrl('http://127.0.0.1:8000/test'));
    }

    public function test_regular_domain_is_not_loopback(): void
    {
        $this->assertFalse($this->linkFlagService->isLoopbackUrl('https://example.com'));
    }
}

