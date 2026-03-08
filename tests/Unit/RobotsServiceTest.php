<?php

namespace Tests\Unit;

use App\Services\RobotsService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class RobotsServiceTest extends TestCase
{
    private RobotsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RobotsService;
    }

    /**
     * Create a mock HTTP client with a predefined response.
     */
    private function createMockClient(int $statusCode, string $body): Client
    {
        $mock = new MockHandler([
            new Response($statusCode, [], $body),
        ]);
        $handlerStack = HandlerStack::create($mock);

        return new Client(['handler' => $handlerStack]);
    }

    /**
     * Create a mock HTTP client that throws an exception.
     */
    private function createExceptionClient(): Client
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(new \Exception('Connection failed'));

        return $mockClient;
    }

    // ===================
    // fetchAndParse tests
    // ===================

    public function test_fetch_and_parse_handles_missing_robots_txt(): void
    {
        $client = $this->createMockClient(404, '');
        $this->service->setClient($client);

        $this->service->fetchAndParse('https://example.com');

        // No robots.txt = everything allowed
        $this->assertTrue($this->service->isAllowed('https://example.com/anything'));
        $this->assertNull($this->service->getCrawlDelay());
        $this->assertEmpty($this->service->getRules());
    }

    public function test_fetch_and_parse_handles_connection_failure(): void
    {
        $this->service->setClient($this->createExceptionClient());

        $this->service->fetchAndParse('https://example.com');

        $this->assertTrue($this->service->isAllowed('https://example.com/anything'));
    }

    public function test_fetch_and_parse_parses_valid_robots_txt(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\nDisallow: /private/\n";
        $client = $this->createMockClient(200, $robotsTxt);
        $this->service->setClient($client);

        $this->service->fetchAndParse('https://example.com');

        $this->assertFalse($this->service->isAllowed('https://example.com/admin'));
        $this->assertFalse($this->service->isAllowed('https://example.com/private/page'));
        $this->assertTrue($this->service->isAllowed('https://example.com/public'));
    }

    public function test_fetch_and_parse_uses_root_origin_when_given_sub_path_url(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\n";

        // Capture the URL that was actually requested
        $requestedUrl = null;
        $mock = new MockHandler([
            new Response(200, [], $robotsTxt),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(function (callable $handler) use (&$requestedUrl) {
            return function ($request, $options) use ($handler, &$requestedUrl) {
                $requestedUrl = (string) $request->getUri();

                return $handler($request, $options);
            };
        });
        $client = new Client(['handler' => $handlerStack]);

        $this->service->setClient($client);
        $this->service->fetchAndParse('https://example.com/about/team');

        $this->assertEquals('https://example.com/robots.txt', $requestedUrl);
        $this->assertFalse($this->service->isAllowed('https://example.com/admin'));
    }

    public function test_fetch_and_parse_uses_root_origin_with_deep_path_and_query(): void
    {
        $requestedUrl = null;
        $mock = new MockHandler([
            new Response(404, [], ''),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(function (callable $handler) use (&$requestedUrl) {
            return function ($request, $options) use ($handler, &$requestedUrl) {
                $requestedUrl = (string) $request->getUri();

                return $handler($request, $options);
            };
        });
        $client = new Client(['handler' => $handlerStack]);

        $this->service->setClient($client);
        $this->service->fetchAndParse('https://example.com/blog/2026/my-post?ref=newsletter');

        $this->assertEquals('https://example.com/robots.txt', $requestedUrl);
    }

    public function test_fetch_and_parse_returns_early_for_malformed_url(): void
    {
        // parse_url() returns false for completely malformed URLs
        // No HTTP request should be made, and the service should remain in a clean state
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->never())->method('request');
        $this->service->setClient($mockClient);

        $result = $this->service->fetchAndParse('http:///no-host-here');

        $this->assertInstanceOf(RobotsService::class, $result);
        $this->assertTrue($this->service->isParsed());
        $this->assertEmpty($this->service->getRules());
        $this->assertNull($this->service->getCrawlDelay());
        $this->assertTrue($this->service->isAllowed('https://example.com/anything'));
    }

    public function test_fetch_and_parse_returns_early_for_url_without_host(): void
    {
        // A relative path has no host component
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->never())->method('request');
        $this->service->setClient($mockClient);

        $result = $this->service->fetchAndParse('/just/a/path');

        $this->assertInstanceOf(RobotsService::class, $result);
        $this->assertEmpty($this->service->getRules());
        $this->assertTrue($this->service->isAllowed('/just/a/path'));
    }

    public function test_fetch_and_parse_returns_early_for_empty_string(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->never())->method('request');
        $this->service->setClient($mockClient);

        $result = $this->service->fetchAndParse('');

        $this->assertInstanceOf(RobotsService::class, $result);
        $this->assertEmpty($this->service->getRules());
    }

    public function test_fetch_and_parse_preserves_custom_port_in_robots_url(): void
    {
        $requestedUrl = null;
        $mock = new MockHandler([
            new Response(404, [], ''),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(function (callable $handler) use (&$requestedUrl) {
            return function ($request, $options) use ($handler, &$requestedUrl) {
                $requestedUrl = (string) $request->getUri();

                return $handler($request, $options);
            };
        });
        $client = new Client(['handler' => $handlerStack]);

        $this->service->setClient($client);
        $this->service->fetchAndParse('https://example.com:8443/some/path');

        $this->assertEquals('https://example.com:8443/robots.txt', $requestedUrl);
    }

    // ===================
    // parseContent tests
    // ===================

    public function test_parse_content_extracts_disallow_rules(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nDisallow: /secret\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(2, $rules);
        $this->assertEquals('disallow', $rules[0]['type']);
        $this->assertEquals('/admin', $rules[0]['pattern']);
        $this->assertEquals('disallow', $rules[1]['type']);
        $this->assertEquals('/secret', $rules[1]['pattern']);
    }

    public function test_parse_content_extracts_allow_rules(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nAllow: /admin/public\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(2, $rules);
        $this->assertEquals('disallow', $rules[0]['type']);
        $this->assertEquals('allow', $rules[1]['type']);
    }

    public function test_parse_content_extracts_crawl_delay(): void
    {
        $content = "User-agent: *\nCrawl-delay: 5\n";
        $this->service->parseContent($content);

        $this->assertEquals(5.0, $this->service->getCrawlDelay());
    }

    public function test_parse_content_extracts_float_crawl_delay(): void
    {
        $content = "User-agent: *\nCrawl-delay: 2.5\n";
        $this->service->parseContent($content);

        $this->assertEquals(2.5, $this->service->getCrawlDelay());
    }

    public function test_parse_content_extracts_sitemap_urls(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nSitemap: https://example.com/sitemap.xml\nSitemap: https://example.com/sitemap2.xml\n";
        $this->service->parseContent($content);

        $sitemaps = $this->service->getSitemapUrls();
        $this->assertCount(2, $sitemaps);
        $this->assertEquals('https://example.com/sitemap.xml', $sitemaps[0]);
        $this->assertEquals('https://example.com/sitemap2.xml', $sitemaps[1]);
    }

    public function test_parse_content_ignores_other_user_agents(): void
    {
        $content = "User-agent: Googlebot\nDisallow: /google-only\n\nUser-agent: *\nDisallow: /everyone\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/everyone', $rules[0]['pattern']);
    }

    public function test_parse_content_handles_empty_disallow(): void
    {
        // Empty Disallow means allow everything (used to explicitly allow all)
        $content = "User-agent: *\nDisallow:\n";
        $this->service->parseContent($content);

        $this->assertEmpty($this->service->getRules());
        $this->assertTrue($this->service->isAllowed('https://example.com/anything'));
    }

    public function test_parse_content_handles_comments(): void
    {
        $content = "# This is a comment\nUser-agent: * # all bots\nDisallow: /admin # admin area\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/admin', $rules[0]['pattern']);
    }

    public function test_parse_content_handles_multiple_user_agent_lines(): void
    {
        // Multiple User-agent lines before directives = same block
        $content = "User-agent: Googlebot\nUser-agent: *\nDisallow: /shared\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/shared', $rules[0]['pattern']);
    }

    public function test_parse_content_prefers_scannrbot_over_wildcard(): void
    {
        $content = "User-agent: *\nDisallow: /wildcard\n\nUser-agent: ScannrBot\nDisallow: /scannr-specific\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/scannr-specific', $rules[0]['pattern']);
    }

    public function test_parse_content_handles_no_wildcard_block(): void
    {
        // Only Googlebot rules, no wildcard = allow everything
        $content = "User-agent: Googlebot\nDisallow: /google-only\n";
        $this->service->parseContent($content);

        $this->assertEmpty($this->service->getRules());
        $this->assertTrue($this->service->isAllowed('https://example.com/google-only'));
    }

    public function test_parse_content_handles_windows_line_endings(): void
    {
        $content = "User-agent: *\r\nDisallow: /admin\r\nDisallow: /private\r\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(2, $rules);
    }

    // ===================
    // isAllowed tests
    // ===================

    public function test_is_allowed_returns_true_when_no_rules(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow:\n");

        $this->assertTrue($this->service->isAllowed('https://example.com/anything'));
    }

    public function test_is_allowed_blocks_disallowed_path(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\n");

        $this->assertFalse($this->service->isAllowed('https://example.com/admin'));
        $this->assertFalse($this->service->isAllowed('https://example.com/admin/page'));
        $this->assertFalse($this->service->isAllowed('https://example.com/admin/'));
    }

    public function test_is_allowed_allows_non_matching_path(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\n");

        $this->assertTrue($this->service->isAllowed('https://example.com/'));
        $this->assertTrue($this->service->isAllowed('https://example.com/public'));
        $this->assertTrue($this->service->isAllowed('https://example.com/contact'));
    }

    public function test_is_allowed_uses_longest_match_wins(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\nAllow: /admin/public\n");

        // /admin is disallowed, but /admin/public is specifically allowed
        $this->assertFalse($this->service->isAllowed('https://example.com/admin'));
        $this->assertFalse($this->service->isAllowed('https://example.com/admin/secret'));
        $this->assertTrue($this->service->isAllowed('https://example.com/admin/public'));
        $this->assertTrue($this->service->isAllowed('https://example.com/admin/public/page'));
    }

    public function test_is_allowed_disallow_root_blocks_everything(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /\n");

        $this->assertFalse($this->service->isAllowed('https://example.com/'));
        $this->assertFalse($this->service->isAllowed('https://example.com/page'));
        $this->assertFalse($this->service->isAllowed('https://example.com/any/path'));
    }

    public function test_is_allowed_returns_true_when_not_parsed(): void
    {
        // Service has not been parsed yet
        $service = new RobotsService;
        $this->assertTrue($service->isAllowed('https://example.com/admin'));
    }

    // ===================
    // pathMatches tests
    // ===================

    public function test_path_matches_with_prefix(): void
    {
        $this->assertTrue($this->service->pathMatches('/admin', '/admin'));
        $this->assertTrue($this->service->pathMatches('/admin/page', '/admin'));
        $this->assertTrue($this->service->pathMatches('/admin/', '/admin'));
        $this->assertFalse($this->service->pathMatches('/public', '/admin'));
    }

    public function test_path_matches_with_end_anchor(): void
    {
        $this->assertTrue($this->service->pathMatches('/admin', '/admin$'));
        $this->assertFalse($this->service->pathMatches('/admin/page', '/admin$'));
        $this->assertFalse($this->service->pathMatches('/admin/', '/admin$'));
    }

    public function test_path_matches_with_wildcard(): void
    {
        $this->assertTrue($this->service->pathMatches('/foo/bar/baz', '/foo/*/baz'));
        $this->assertTrue($this->service->pathMatches('/foo/anything/baz', '/foo/*/baz'));
        $this->assertFalse($this->service->pathMatches('/foo/bar/qux', '/foo/*/baz'));
    }

    public function test_path_matches_with_wildcard_and_end_anchor(): void
    {
        $this->assertTrue($this->service->pathMatches('/page.html', '/*.html$'));
        $this->assertFalse($this->service->pathMatches('/page.html/extra', '/*.html$'));
    }

    public function test_path_matches_trailing_wildcard(): void
    {
        // /private/* should match anything under /private/
        $this->assertTrue($this->service->pathMatches('/private/page', '/private/*'));
        $this->assertTrue($this->service->pathMatches('/private/', '/private/*'));
    }

    public function test_path_matches_with_query_string_pattern(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /search\n");

        // Disallow /search also blocks /search?q=test
        $this->assertFalse($this->service->isAllowed('https://example.com/search?q=test'));
        $this->assertFalse($this->service->isAllowed('https://example.com/search'));
    }

    // ===================
    // Crawl-delay tests
    // ===================

    public function test_get_crawl_delay_returns_null_when_not_set(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\n");

        $this->assertNull($this->service->getCrawlDelay());
    }

    public function test_get_crawl_delay_returns_integer_delay(): void
    {
        $this->service->parseContent("User-agent: *\nCrawl-delay: 10\n");

        $this->assertEquals(10.0, $this->service->getCrawlDelay());
    }

    public function test_get_crawl_delay_returns_float_delay(): void
    {
        $this->service->parseContent("User-agent: *\nCrawl-delay: 0.5\n");

        $this->assertEquals(0.5, $this->service->getCrawlDelay());
    }

    public function test_get_crawl_delay_ignores_other_agents(): void
    {
        $content = "User-agent: Googlebot\nCrawl-delay: 100\n\nUser-agent: *\nCrawl-delay: 5\n";
        $this->service->parseContent($content);

        $this->assertEquals(5.0, $this->service->getCrawlDelay());
    }

    public function test_get_crawl_delay_does_not_pick_up_later_agent_delays(): void
    {
        // This is a regression test for a bug where Crawl-delay from later
        // User-agent blocks (like AhrefsBot: 5) was incorrectly used instead
        // of the wildcard block's Crawl-delay: 1
        $content = <<<'ROBOTS'
User-agent: *
Allow: /
Disallow: /api/
Crawl-delay: 1

User-agent: Googlebot
Allow: /
Crawl-delay: 0

User-agent: AhrefsBot
Crawl-delay: 5

User-agent: SemrushBot
Crawl-delay: 5

Sitemap: https://example.com/sitemap.xml
ROBOTS;

        $this->service->parseContent($content);

        // Should be 1 (from User-agent: *), NOT 5 (from AhrefsBot)
        $this->assertEquals(1.0, $this->service->getCrawlDelay());
    }

    public function test_disallow_rules_not_picked_up_from_other_agents(): void
    {
        // Ensure Disallow rules from other agents are also not picked up
        $content = <<<'ROBOTS'
User-agent: *
Disallow: /admin/

User-agent: MJ12bot
Disallow: /

User-agent: DotBot
Disallow: /
ROBOTS;

        $this->service->parseContent($content);

        $rules = $this->service->getRules();

        // Should only have 1 rule (from *), not 3
        $this->assertCount(1, $rules);
        $this->assertEquals('/admin/', $rules[0]['pattern']);

        // Root should be allowed (only blocked for MJ12bot/DotBot)
        $this->assertTrue($this->service->isAllowed('https://example.com/'));
        $this->assertFalse($this->service->isAllowed('https://example.com/admin/page'));
    }

    // ===================
    // Sitemap URL extraction tests
    // ===================

    public function test_get_sitemap_urls_returns_empty_when_none(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\n");

        $this->assertEmpty($this->service->getSitemapUrls());
    }

    public function test_get_sitemap_urls_extracts_all_sitemaps(): void
    {
        $content = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow: /admin\nSitemap: https://example.com/sitemap2.xml\n";
        $this->service->parseContent($content);

        $sitemaps = $this->service->getSitemapUrls();
        $this->assertCount(2, $sitemaps);
    }

    // ===================
    // Real-world robots.txt tests
    // ===================

    public function test_real_world_robots_txt_basic(): void
    {
        $content = <<<'ROBOTS'
User-agent: *
Disallow: /wp-admin/
Disallow: /wp-includes/
Allow: /wp-admin/admin-ajax.php

Sitemap: https://example.com/sitemap.xml
ROBOTS;

        $this->service->parseContent($content);

        $this->assertFalse($this->service->isAllowed('https://example.com/wp-admin/'));
        $this->assertFalse($this->service->isAllowed('https://example.com/wp-admin/plugins.php'));
        $this->assertFalse($this->service->isAllowed('https://example.com/wp-includes/js/jquery.js'));
        $this->assertTrue($this->service->isAllowed('https://example.com/wp-admin/admin-ajax.php'));
        $this->assertTrue($this->service->isAllowed('https://example.com/'));
        $this->assertTrue($this->service->isAllowed('https://example.com/blog/'));
    }

    public function test_real_world_robots_txt_with_crawl_delay(): void
    {
        $content = <<<'ROBOTS'
User-agent: *
Crawl-delay: 10
Disallow: /cgi-bin/
Disallow: /tmp/
Disallow: /private/

Sitemap: https://example.com/sitemap.xml
ROBOTS;

        $this->service->parseContent($content);

        $this->assertEquals(10.0, $this->service->getCrawlDelay());
        $this->assertFalse($this->service->isAllowed('https://example.com/cgi-bin/script'));
        $this->assertFalse($this->service->isAllowed('https://example.com/tmp/file'));
        $this->assertFalse($this->service->isAllowed('https://example.com/private/'));
        $this->assertTrue($this->service->isAllowed('https://example.com/public/'));
    }

    public function test_real_world_robots_txt_block_all(): void
    {
        $content = "User-agent: *\nDisallow: /\n";
        $this->service->parseContent($content);

        $this->assertFalse($this->service->isAllowed('https://example.com/'));
        $this->assertFalse($this->service->isAllowed('https://example.com/page'));
    }

    public function test_real_world_robots_txt_allow_all(): void
    {
        $content = "User-agent: *\nDisallow:\n";
        $this->service->parseContent($content);

        $this->assertTrue($this->service->isAllowed('https://example.com/'));
        $this->assertTrue($this->service->isAllowed('https://example.com/page'));
    }

    public function test_real_world_robots_txt_with_wildcards(): void
    {
        $content = <<<'ROBOTS'
User-agent: *
Disallow: /*.pdf$
Disallow: /print/*/
Allow: /
ROBOTS;

        $this->service->parseContent($content);

        $this->assertFalse($this->service->isAllowed('https://example.com/document.pdf'));
        $this->assertTrue($this->service->isAllowed('https://example.com/document.pdf/view'));
        $this->assertFalse($this->service->isAllowed('https://example.com/print/123/'));
        $this->assertTrue($this->service->isAllowed('https://example.com/page'));
    }

    // ===================
    // reset and state tests
    // ===================

    public function test_reset_clears_all_state(): void
    {
        $this->service->parseContent("User-agent: *\nDisallow: /admin\nCrawl-delay: 5\nSitemap: https://example.com/sitemap.xml\n");

        $this->assertNotEmpty($this->service->getRules());
        $this->assertNotNull($this->service->getCrawlDelay());
        $this->assertNotEmpty($this->service->getSitemapUrls());
        $this->assertTrue($this->service->isParsed());

        $this->service->reset();

        $this->assertEmpty($this->service->getRules());
        $this->assertNull($this->service->getCrawlDelay());
        $this->assertEmpty($this->service->getSitemapUrls());
        $this->assertFalse($this->service->isParsed());
    }

    public function test_fetch_and_parse_resets_previous_state(): void
    {
        // First parse
        $this->service->parseContent("User-agent: *\nDisallow: /admin\nCrawl-delay: 5\n");
        $this->assertCount(1, $this->service->getRules());
        $this->assertEquals(5.0, $this->service->getCrawlDelay());

        // Fetch with 404 should reset
        $client = $this->createMockClient(404, '');
        $this->service->setClient($client);
        $this->service->fetchAndParse('https://example.com');

        $this->assertEmpty($this->service->getRules());
        $this->assertNull($this->service->getCrawlDelay());
    }

    // ===================
    // ScannrBot-specific tests
    // ===================

    public function test_scannrbot_specific_crawl_delay_overrides_wildcard(): void
    {
        $content = "User-agent: *\nCrawl-delay: 2\n\nUser-agent: ScannrBot\nCrawl-delay: 10\n";
        $this->service->parseContent($content);

        $this->assertEquals(10.0, $this->service->getCrawlDelay());
    }

    public function test_scannrbot_specific_rules_override_wildcard(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nDisallow: /private\n\nUser-agent: ScannrBot\nDisallow: /bot-specific\n";
        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/bot-specific', $rules[0]['pattern']);

        // /admin should now be allowed (ScannrBot rules don't include it)
        $this->assertTrue($this->service->isAllowed('https://example.com/admin'));
        $this->assertFalse($this->service->isAllowed('https://example.com/bot-specific'));
    }

    // ===================
    // Redirect handling tests
    // ===================

    public function test_fetch_and_parse_follows_redirects(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\nSitemap: https://example.com/sitemap.xml\n";

        $mock = new MockHandler([
            new Response(301, ['Location' => 'https://www.example.com/robots.txt']),
            new Response(200, [], $robotsTxt),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $this->service->setClient($client);
        $this->service->fetchAndParse('https://example.com');

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/admin', $rules[0]['pattern']);
        $this->assertContains('https://example.com/sitemap.xml', $this->service->getSitemapUrls());
    }

    public function test_fetch_and_parse_follows_redirects_with_no_redirect_client(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /private/\nCrawl-delay: 3\n";

        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://www.example.com/robots.txt']),
            new Response(200, [], $robotsTxt),
        ]);
        $handlerStack = HandlerStack::create($mock);
        // Simulate CrawlerService's client that has allow_redirects => false by default
        $client = new Client([
            'handler' => $handlerStack,
            'allow_redirects' => false,
        ]);

        $this->service->setClient($client);
        $this->service->fetchAndParse('https://www.example.com');

        $rules = $this->service->getRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('/private/', $rules[0]['pattern']);
        $this->assertEquals(3.0, $this->service->getCrawlDelay());
    }

    // ===================
    // WordPress / Yoast robots.txt tests
    // ===================

    public function test_parse_content_handles_wordpress_yoast_robots_txt(): void
    {
        $content = <<<'ROBOTS'
User-agent: *
Disallow: /wp-content/uploads/wc-logs/
Disallow: /wp-content/uploads/woocommerce_transient_files/
Disallow: /wp-content/uploads/woocommerce_uploads/
Disallow: /*?add-to-cart=
Disallow: /*?*add-to-cart=
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php

# START YOAST BLOCK
# ---------------------------
User-agent: *
Disallow:

Sitemap: https://www.japkejanneke.nl/sitemap_index.xml
# ---------------------------
# END YOAST BLOCK
ROBOTS;

        $this->service->parseContent($content);

        $rules = $this->service->getRules();
        $this->assertCount(7, $rules);

        // Verify disallow rules from first block are preserved
        $this->assertEquals('disallow', $rules[0]['type']);
        $this->assertEquals('/wp-content/uploads/wc-logs/', $rules[0]['pattern']);
        $this->assertEquals('disallow', $rules[5]['type']);
        $this->assertEquals('/wp-admin/', $rules[5]['pattern']);

        // Verify allow rule
        $this->assertEquals('allow', $rules[6]['type']);
        $this->assertEquals('/wp-admin/admin-ajax.php', $rules[6]['pattern']);

        // Verify sitemap URL extracted
        $sitemaps = $this->service->getSitemapUrls();
        $this->assertCount(1, $sitemaps);
        $this->assertEquals('https://www.japkejanneke.nl/sitemap_index.xml', $sitemaps[0]);

        // Verify rules are enforced correctly
        $this->assertFalse($this->service->isAllowed('https://www.japkejanneke.nl/wp-admin/'));
        $this->assertTrue($this->service->isAllowed('https://www.japkejanneke.nl/wp-admin/admin-ajax.php'));
        $this->assertFalse($this->service->isAllowed('https://www.japkejanneke.nl/shop?add-to-cart=123'));
        $this->assertTrue($this->service->isAllowed('https://www.japkejanneke.nl/shop/'));
    }
}
