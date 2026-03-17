<?php

namespace Tests\Unit;

use Scannr\Enums\LinkFlag;
use Scannr\Services\LinkFlagService;
use Scannr\Services\SeverityEvaluator;
use Scannr\Services\UrlNormalizer;
use Tests\TestCase;

class LinkFlagServiceTest extends TestCase
{
    private LinkFlagService $linkFlagService;

    private UrlNormalizer $urlNormalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer;
        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');
        $severityEvaluator = new SeverityEvaluator;
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

    public function test_url_with_path_parameter_is_flagged_as_indirect_reference(): void
    {
        $url = 'https://example.com/user/{userId}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_backtick_is_flagged_as_malformed(): void
    {
        $url = 'https://example.com/test`';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_trailing_comma_word_is_flagged_as_indirect_reference(): void
    {
        $url = 'https://example.com/test,param';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
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
    // detectFromUrl tests - flag separation (malformed vs indirect)
    // ===================

    public function test_backtick_only_fires_malformed_not_indirect(): void
    {
        $url = 'https://example.com/test`';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_newline_only_fires_malformed_not_indirect(): void
    {
        $url = "https://example.com/test\nmore";
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_closing_brace_context_fires_indirect_not_malformed(): void
    {
        $url = 'https://example.com/path}rest';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_template_literal_fires_both_malformed_and_indirect(): void
    {
        // ${userId} has ${ (malformed) AND {U (indirect)
        $url = 'https://example.com/user/${userId}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_ruby_interpolation_fires_both_malformed_and_indirect(): void
    {
        // #{name} has #{ (malformed) AND {n (indirect)
        $url = 'https://example.com/user/#{name}';
        $flags = $this->linkFlagService->detectFromUrl($url, false);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

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

    public function test_suspicious_syntax_adds_malformed_flag(): void
    {
        $flags = $this->linkFlagService->detectFromDiscovery(
            isJsRendered: false,
            fromJsBundle: true,
            hasSuspiciousSyntax: true
        );

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
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
    // LinkedIn URL specific tests
    // ===================

    public function test_linkedin_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://www.linkedin.com/in/jesse-sommeling';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        // Should only have external_platform flag
        $this->assertContains(LinkFlag::EXTERNAL_PLATFORM, $flags);
    }

    public function test_linkedin_url_with_trailing_slash_is_not_flagged_as_malformed(): void
    {
        $url = 'https://www.linkedin.com/in/jesse-sommeling/';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_linkedin_company_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://www.linkedin.com/company/example-corp';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    // ===================
    // Clean external URL tests (should NEVER be flagged as malformed)
    // ===================

    public function test_github_profile_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://github.com/JBSommeling';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertContains(LinkFlag::EXTERNAL_PLATFORM, $flags);
    }

    public function test_github_repo_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://github.com/laravel/framework';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_twitter_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://twitter.com/laaboratories';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_youtube_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_pusher_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://pusher.com';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_js_pusher_url_is_not_flagged_as_malformed(): void
    {
        $url = 'https://js.pusher.com';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_http_js_pusher_url_is_not_flagged_as_malformed(): void
    {
        $url = 'http://js.pusher.com';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_example_com_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertNotContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_url_with_path_segments_is_not_flagged_as_malformed(): void
    {
        $url = 'https://docs.laravel.com/10.x/routing';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_hash_fragment_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com/page#section';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_port_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com:8080/api';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_username_password_is_not_flagged_as_malformed(): void
    {
        $url = 'https://user:pass@example.com/api';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_complex_query_string_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com/search?q=test&filter=active&sort=desc&page=1';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_encoded_characters_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com/search?q=hello%20world&name=John%20Doe';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_hyphen_in_domain_is_not_flagged_as_malformed(): void
    {
        $url = 'https://my-awesome-site.example.com/page';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_numbers_in_path_is_not_flagged_as_malformed(): void
    {
        $url = 'https://example.com/user/12345/profile';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    // ===================
    // URLs that SHOULD be flagged as malformed
    // ===================

    public function test_url_with_dollar_brace_template_is_flagged(): void
    {
        $url = 'https://api.example.com/users/${userId}/profile';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
    }

    public function test_url_with_hash_brace_template_is_flagged(): void
    {
        $url = 'https://api.example.com/users/#{userId}/profile';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_single_brace_variable_is_flagged(): void
    {
        $url = 'https://api.example.com/users/{id}';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_backtick_is_flagged(): void
    {
        $url = 'https://example.com/path`';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_with_embedded_newline_is_flagged(): void
    {
        $url = "https://example.com/path\nmalicious";
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::MALFORMED_URL, $flags);
    }

    public function test_url_ending_with_comma_and_word_is_flagged(): void
    {
        // This pattern suggests string concatenation: "url",variable
        $url = 'https://example.com/api,userId';
        $flags = $this->linkFlagService->detectFromUrl($url, true);

        $this->assertContains(LinkFlag::INDIRECT_REFERENCE, $flags);
        $this->assertNotContains(LinkFlag::MALFORMED_URL, $flags);
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

    // ===================
    // isLocalhostUrl tests (comprehensive)
    // ===================

    public function test_localhost_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://localhost'));
    }

    public function test_localhost_with_port_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://localhost:3000'));
    }

    public function test_localhost_with_path_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://localhost/api/users'));
    }

    public function test_127_0_0_1_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://127.0.0.1:8080'));
    }

    public function test_ipv6_localhost_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://[::1]:8080'));
    }

    public function test_0_0_0_0_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://0.0.0.0:8000'));
    }

    public function test_dot_local_domain_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://myapp.local'));
    }

    public function test_dot_test_domain_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://myapp.test'));
    }

    public function test_dot_localhost_domain_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://dev.localhost'));
    }

    public function test_dot_invalid_domain_is_localhost_url(): void
    {
        $this->assertTrue($this->linkFlagService->isLocalhostUrl('http://test.invalid'));
    }

    public function test_production_url_is_not_localhost_url(): void
    {
        $this->assertFalse($this->linkFlagService->isLocalhostUrl('https://www.sommeling.dev'));
    }

    public function test_external_url_is_not_localhost_url(): void
    {
        $this->assertFalse($this->linkFlagService->isLocalhostUrl('https://github.com/JBSommeling'));
    }

    public function test_example_com_is_not_localhost_url(): void
    {
        // example.com is a real domain, not .example TLD
        $this->assertFalse($this->linkFlagService->isLocalhostUrl('https://example.com'));
    }

    // ===================
    // detectFromUrl with LOCALHOST_URL flag
    // ===================

    public function test_detect_from_url_flags_localhost(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('http://localhost', true);

        $this->assertContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_flags_localhost_with_port(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('http://localhost:3000/api', true);

        $this->assertContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_flags_127_0_0_1(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('http://127.0.0.1:8080', true);

        $this->assertContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_flags_dot_local(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('http://myapp.local', true);

        $this->assertContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_flags_dot_test(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('http://laravel.test', true);

        $this->assertContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_does_not_flag_production_as_localhost(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('https://www.sommeling.dev', false);

        $this->assertNotContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_does_not_flag_external_as_localhost(): void
    {
        $flags = $this->linkFlagService->detectFromUrl('https://github.com/user', true);

        $this->assertNotContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }

    public function test_detect_from_url_does_not_flag_example_com_as_localhost(): void
    {
        // example.com is a real domain (not .example TLD)
        $flags = $this->linkFlagService->detectFromUrl('https://example.com', true);

        $this->assertNotContains(LinkFlag::DEVELOPER_LEFTOVER, $flags);
    }
}
