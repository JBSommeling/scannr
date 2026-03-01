<?php

namespace Tests\Unit;

use App\DTO\ScanConfig;
use App\Services\BrowsershotFetcher;
use App\Services\CrawlerService;
use App\Services\ScannerService;
use App\Services\SitemapService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Random\RandomException;
use Tests\TestCase;

class CrawlerServiceTest extends TestCase
{
    private function createMockClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        return new Client([
            'handler' => $handlerStack,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    private function createConfig(array $overrides = []): ScanConfig
    {
        return new ScanConfig(
            baseUrl: $overrides['baseUrl'] ?? 'https://example.com',
            maxDepth: $overrides['maxDepth'] ?? 3,
            maxUrls: $overrides['maxUrls'] ?? 100,
            timeout: $overrides['timeout'] ?? 5,
            scanElements: $overrides['scanElements'] ?? ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: $overrides['statusFilter'] ?? 'all',
            elementFilter: $overrides['elementFilter'] ?? 'all',
            outputFormat: $overrides['outputFormat'] ?? 'table',
            delayMin: $overrides['delayMin'] ?? 0,
            delayMax: $overrides['delayMax'] ?? 0,
            useSitemap: $overrides['useSitemap'] ?? false,
            customTrackingParams: $overrides['customTrackingParams'] ?? [],
            useJsRendering: $overrides['useJsRendering'] ?? false,
            respectRobots: $overrides['respectRobots'] ?? false,
        );
    }

    // ==================
    // Constructor tests
    // ==================

    public function test_crawler_service_can_be_instantiated(): void
    {
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);

        $this->assertInstanceOf(CrawlerService::class, $crawler);
    }

    // ==================
    // Crawl tests
    // ==================

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_crawl_returns_results_array(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Page 1</body></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 2]);
        $crawlResult = $crawler->crawl($config);

        $this->assertIsArray($crawlResult);
        $this->assertArrayHasKey('results', $crawlResult);
        $this->assertArrayHasKey('aborted', $crawlResult);
        $this->assertNotEmpty($crawlResult['results']);
    }

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_crawl_respects_max_urls_limit(): void
    {
        $html = '<html><body>
            <a href="/page1">Link 1</a>
            <a href="/page2">Link 2</a>
            <a href="/page3">Link 3</a>
        </body></html>';

        $responses = [
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 2]);
        $crawlResult = $crawler->crawl($config);

        $this->assertLessThanOrEqual(2, count($crawlResult['results']));
    }

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_crawl_respects_max_depth(): void
    {
        // Page at depth 0 links to page at depth 1
        $html0 = '<html><body><a href="/level1">Level 1</a></body></html>';
        // Page at depth 1 links to page at depth 2
        $html1 = '<html><body><a href="/level2">Level 2</a></body></html>';
        // Page at depth 2 links to page at depth 3
        $html2 = '<html><body><a href="/level3">Level 3</a></body></html>';
        // Page at depth 3 (should not be crawled with maxDepth=2)
        $html3 = '<html><body>Level 3</body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html0),
            new Response(200, ['Content-Type' => 'text/html'], $html1),
            new Response(200, ['Content-Type' => 'text/html'], $html2),
            new Response(200, ['Content-Type' => 'text/html'], $html3),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxDepth' => 2, 'maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should have crawled: base url (depth 0), /level1 (depth 1), /level2 (depth 2)
        // Should NOT have crawled: /level3 (depth 3)
        $urls = array_column($crawlResult['results'], 'url');
        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/level1', $urls);
        $this->assertContains('https://example.com/level2', $urls);
        $this->assertNotContains('https://example.com/level3', $urls);
    }

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_crawl_tracks_visited_urls(): void
    {
        // Page links to same URL multiple times
        $html = '<html><body>
            <a href="/page1">Link 1</a>
            <a href="/page1">Link 1 again</a>
            <a href="/page1">Link 1 third time</a>
        </body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should only have 2 results: base URL and /page1 (not duplicates)
        $this->assertCount(2, $crawlResult['results']);
    }

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_crawl_calls_progress_callback(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $progressCalls = [];
        $config = $this->createConfig(['maxUrls' => 10]);

        $crawler->crawl($config, function (int $scanned, int $total) use (&$progressCalls) {
            $progressCalls[] = ['scanned' => $scanned, 'total' => $total];
        });

        $this->assertNotEmpty($progressCalls);
        $this->assertEquals(1, $progressCalls[0]['scanned']);
    }

    public function test_crawl_processes_external_urls(): void
    {
        $html = '<html><body><a href="https://external.com/page">External</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, [], ''), // HEAD request for external URL
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        $externalResults = array_filter($crawlResult['results'], fn($r) => $r['type'] === 'external');
        $this->assertNotEmpty($externalResults);
    }

    public function test_crawl_filters_by_scan_elements(): void
    {
        $html = '<html><body>
            <a href="/page1">Link</a>
            <img src="/image.jpg" />
            <script src="/script.js"></script>
        </body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, [], ''),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // Only scan 'a' elements
        $config = $this->createConfig(['scanElements' => ['a'], 'maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should only have results for 'a' elements
        foreach ($crawlResult['results'] as $result) {
            if ($result['url'] !== 'https://example.com') {
                $this->assertEquals('a', $result['sourceElement']);
            }
        }
    }

    public function test_crawl_adds_custom_tracking_params(): void
    {
        $html = '<html><body><a href="/page1?custom_tracker=123">Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'customTrackingParams' => ['custom_tracker'],
            'maxUrls' => 10,
        ]);
        $crawlResult = $crawler->crawl($config);

        // The URL should have the tracking param stripped
        $urls = array_column($crawlResult['results'], 'url');
        $this->assertContains('https://example.com/page1', $urls);
        $this->assertNotContains('https://example.com/page1?custom_tracker=123', $urls);
    }

    public function test_crawl_deduplicates_urls_differing_only_by_tracking_params(): void
    {
        // Page links to the same URL with different tracking params
        $html = '<html><body>
            <a href="/page1?utm_source=google">Link 1</a>
            <a href="/page1?fbclid=abc123">Link 2</a>
            <a href="/page1">Link 3</a>
        </body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should only have 2 results: base URL and /page1 (not 3 duplicates)
        $this->assertCount(2, $crawlResult['results']);
    }

    public function test_crawl_deduplicates_urls_differing_by_trailing_slash(): void
    {
        // Page links to the same URL with and without trailing slash
        $html = '<html><body>
            <a href="/page1/">With slash</a>
            <a href="/page1">Without slash</a>
        </body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should only have 2 results: base URL and /page1 (not duplicated)
        $this->assertCount(2, $crawlResult['results']);
    }

    public function test_crawl_deduplicates_urls_differing_by_fragment(): void
    {
        // Page links to the same URL with different fragments
        $html = '<html><body>
            <a href="/page1#section1">Section 1</a>
            <a href="/page1#section2">Section 2</a>
            <a href="/page1">No fragment</a>
        </body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        // Should only have 2 results: base URL and /page1 (fragments don't create distinct pages)
        $this->assertCount(2, $crawlResult['results']);
    }

    // ==================
    // Sitemap integration tests
    // ==================

    public function test_crawl_with_sitemap_enabled_calls_callback(): void
    {
        $sitemap = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
            </urlset>';

        $client = $this->createMockClient([
            // Robots.txt check
            new Response(404, [], ''),
            // Sitemap.xml check
            new Response(200, ['Content-Type' => 'application/xml'], $sitemap),
            // Base URL crawl
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
            // Page from sitemap
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $sitemapMessages = [];
        $config = $this->createConfig(['useSitemap' => true, 'maxUrls' => 10]);

        $crawler->crawl(
            $config,
            null,
            function (string $message) use (&$sitemapMessages) {
                $sitemapMessages[] = $message;
            }
        );

        $this->assertNotEmpty($sitemapMessages);
        $this->assertStringContainsString('sitemap', strtolower($sitemapMessages[0]));
    }

    // ==================
    // State management tests
    // ==================

    public function test_crawl_resets_state_between_runs(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            // First crawl
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
            // Second crawl
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);

        // First crawl
        $crawlResult1 = $crawler->crawl($config);

        // Second crawl should start fresh
        $crawlResult2 = $crawler->crawl($config);

        $this->assertEquals(count($crawlResult1['results']), count($crawlResult2['results']));
    }

    public function test_get_results_returns_current_results(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawler->crawl($config);

        $results = $crawler->getResults();
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    public function test_get_visited_returns_visited_urls(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawler->crawl($config);

        $visited = $crawler->getVisited();
        $this->assertIsArray($visited);
        $this->assertArrayHasKey('https://example.com', $visited);
    }

    /**
     * @throws GuzzleException
     * @throws RandomException
     */
    public function test_www_to_non_www_redirect_marks_canonical_as_visited(): void
    {
        // Start URL (www) redirects to non-www
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            // www.example.com redirects to example.com
            new Response(301, ['Location' => 'https://example.com']),
            // example.com (canonical) returns HTML
            new Response(200, ['Content-Type' => 'text/html'], $html),
            // /page1
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'baseUrl' => 'https://www.example.com',
            'maxUrls' => 10
        ]);
        $crawler->crawl($config);

        $visited = $crawler->getVisited();
        // Both the original URL and the canonical URL should be marked as visited
        $this->assertArrayHasKey('https://www.example.com', $visited);
        $this->assertArrayHasKey('https://example.com', $visited);
    }

    public function test_sitemap_homepage_is_deduplicated_after_www_redirect(): void
    {
        // This tests the specific scenario where:
        // 1. Start URL is www.example.com
        // 2. www.example.com redirects to example.com
        // 3. Sitemap contains example.com (which should be deduplicated)
        $html = '<html><body><a href="/about">About</a></body></html>';

        $client = $this->createMockClient([
            // www.example.com redirects to example.com
            new Response(301, ['Location' => 'https://example.com']),
            // example.com (canonical) returns HTML
            new Response(200, ['Content-Type' => 'text/html'], $html),
            // /about page
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'baseUrl' => 'https://www.example.com',
            'maxUrls' => 10
        ]);
        $crawlResult = $crawler->crawl($config);

        // Should only have one result for the homepage (not two for www and non-www)
        $homepageResults = array_filter($crawlResult['results'], function ($r) {
            $url = $r['url'] ?? '';
            return $url === 'https://www.example.com' || $url === 'https://example.com';
        });

        $this->assertCount(1, $homepageResults, 'Homepage should only appear once in results');
    }

    // ==================
    // JavaScript rendering tests
    // ==================

    public function test_crawl_with_js_rendering_shows_enabled_message(): void
    {
        $html = '<html><body><div id="root"></div></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $messages = [];
        $config = $this->createConfig(['useJsRendering' => true, 'maxUrls' => 1]);

        $crawler->crawl(
            $config,
            null,
            function (string $message) use (&$messages) {
                $messages[] = $message;
            }
        );

        // When JS rendering is enabled, the callback should receive a message
        // about either successful enablement or a fallback warning
        $jsMessages = array_filter($messages, fn($m) =>
            stripos($m, 'javascript') !== false || stripos($m, 'puppeteer') !== false
        );
        $this->assertNotEmpty($jsMessages, 'Should display a JS rendering status message');
    }

    public function test_crawl_without_js_rendering_does_not_show_js_message(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $messages = [];
        $config = $this->createConfig(['useJsRendering' => false, 'maxUrls' => 2]);

        $crawler->crawl(
            $config,
            null,
            function (string $message) use (&$messages) {
                $messages[] = $message;
            }
        );

        $jsMessages = array_filter($messages, fn($m) => stripos($m, 'javascript') !== false);
        $this->assertEmpty($jsMessages, 'Should NOT display JS rendering message when disabled');
    }

    public function test_crawl_with_js_rendering_extracts_js_rendered_links(): void
    {
        // Simulate SPA: raw HTML is just a shell, JS renders the actual content
        $spaShell = '<html><body><div id="root"></div></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $spaShell),
            // The image discovered by Browsershot
            new Response(200, [], ''),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();

        // Inject a mock BrowsershotFetcher directly into the scanner service
        $renderedHtml = '<html><body><div id="root"><img src="https://cdn.example.com/hero.webp" /></div></body></html>';
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 200,
            'body' => $renderedHtml,
            'finalUrl' => 'https://example.com',
        ]);
        $scannerService->setBrowsershotFetcher($mockFetcher);

        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 5, 'scanElements' => ['a', 'img']]);
        $crawlResult = $crawler->crawl($config);

        // Should find the image from JS-rendered content
        $urls = array_column($crawlResult['results'], 'url');
        $this->assertContains('https://cdn.example.com/hero.webp', $urls);
    }

    public function test_crawl_with_js_rendering_disabled_does_not_find_js_content(): void
    {
        // Simulate SPA: raw HTML is just a shell with no content
        $spaShell = '<html><body><div id="root"></div></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $spaShell),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // No JS rendering, no BrowsershotFetcher
        $config = $this->createConfig(['maxUrls' => 5]);
        $crawlResult = $crawler->crawl($config);

        // Only the base URL itself should appear - no links extracted from SPA shell
        $this->assertCount(1, $crawlResult['results']);
        $this->assertEquals('https://example.com', $crawlResult['results'][0]['url']);
    }

    public function test_crawl_with_js_rendering_falls_back_on_browsershot_failure(): void
    {
        // Raw HTML has a link (fallback content)
        $html = '<html><body><a href="/static-link">Static Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();

        // Browsershot fails
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 'Error',
            'body' => null,
            'finalUrl' => 'https://example.com',
            'error' => 'Chrome crashed',
        ]);
        $scannerService->setBrowsershotFetcher($mockFetcher);

        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 5]);
        $crawlResult = $crawler->crawl($config);

        // Should still find the static link from Guzzle response
        $urls = array_column($crawlResult['results'], 'url');
        $this->assertContains('https://example.com/static-link', $urls);
    }

    // ===================
    // User-Agent tests
    // ===================

    public function test_crawler_sends_scannrbot_user_agent(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $history = [];
        $historyMiddleware = \GuzzleHttp\Middleware::history($history);

        $mock = new \GuzzleHttp\Handler\MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
        ]);

        $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
        $handlerStack->push($historyMiddleware);

        $client = new Client([
            'handler' => $handlerStack,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => config('scanner.user_agent', 'ScannrBot/1.0 (+https://scannr.io)'),
            ],
        ]);

        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10, 'delayMin' => 0, 'delayMax' => 0]);
        $crawler->crawl($config);

        $this->assertNotEmpty($history, 'Expected at least one HTTP request');

        foreach ($history as $transaction) {
            $userAgent = $transaction['request']->getHeaderLine('User-Agent');
            $this->assertStringContainsString('ScannrBot', $userAgent);
            $this->assertStringNotContainsString('Mozilla', $userAgent);
            $this->assertStringNotContainsString('Chrome', $userAgent);
        }
    }

    // ===================
    // Rate Limit Backoff (429) tests
    // ===================

    public function test_crawl_retries_on_429_with_backoff(): void
    {
        // First request returns 429, second succeeds
        $responses = [
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Success</body></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // Set low backoff for test speed
        config(['scanner.rate_limit' => [
            'backoff_delays' => [10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 10,
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertFalse($crawlResult['aborted']);
        $this->assertNull($crawlResult['error']);
        $this->assertNotEmpty($crawlResult['results']);
        $this->assertEquals(200, $crawlResult['results'][0]['status']);
    }

    public function test_crawl_aborts_after_max_429_responses(): void
    {
        // All requests return 429
        $responses = array_fill(0, 20, new Response(429, ['Content-Type' => 'text/html'], ''));

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // Set low backoff with enough retries to reach abort threshold
        // We have 1 URL, and with 5 backoff delays we get 6 requests (1 original + 5 retries)
        // Setting max_429_before_abort to 3 means we abort on the 3rd 429
        config(['scanner.rate_limit' => [
            'backoff_delays' => [10, 10, 10, 10, 10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 3,
        ]]);

        $config = $this->createConfig(['maxUrls' => 10, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertTrue($crawlResult['aborted']);
        $this->assertEquals('Scan aborted due to rate limiting', $crawlResult['error']);
    }

    public function test_crawl_respects_retry_after_header(): void
    {
        $startTime = microtime(true);

        // 429 with Retry-After header of 1 second, then success
        $responses = [
            new Response(429, ['Content-Type' => 'text/html', 'Retry-After' => '1'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Success</body></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        config(['scanner.rate_limit' => [
            'backoff_delays' => [10], // Very short default
            'respect_retry_after' => true,
            'max_429_before_abort' => 10,
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $elapsed = microtime(true) - $startTime;

        // Should have waited approximately 1 second due to Retry-After header
        $this->assertGreaterThan(0.9, $elapsed, 'Should have waited for Retry-After header');
        $this->assertFalse($crawlResult['aborted']);
        $this->assertEquals(200, $crawlResult['results'][0]['status']);
    }

    public function test_crawl_uses_backoff_delays_when_no_retry_after(): void
    {
        $startTime = microtime(true);

        // 429 without Retry-After, then success
        $responses = [
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Success</body></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // Set specific backoff delay
        config(['scanner.rate_limit' => [
            'backoff_delays' => [500], // 500ms
            'respect_retry_after' => true,
            'max_429_before_abort' => 10,
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $elapsed = microtime(true) - $startTime;

        // Should have waited approximately 500ms
        $this->assertGreaterThan(0.4, $elapsed, 'Should have waited for backoff delay');
        $this->assertLessThan(1.0, $elapsed, 'Should not have waited too long');
        $this->assertFalse($crawlResult['aborted']);
    }

    public function test_crawl_escalates_backoff_delays(): void
    {
        $startTime = microtime(true);

        // Multiple 429s, then success
        $responses = [
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Success</body></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // Set escalating backoff delays: 200ms, then 400ms
        config(['scanner.rate_limit' => [
            'backoff_delays' => [200, 400],
            'respect_retry_after' => false,
            'max_429_before_abort' => 10,
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $elapsed = microtime(true) - $startTime;

        // Should have waited 200ms + 400ms = ~600ms total
        $this->assertGreaterThan(0.5, $elapsed, 'Should have waited for both backoff delays');
        $this->assertFalse($crawlResult['aborted']);
        $this->assertEquals(200, $crawlResult['results'][0]['status']);
    }

    public function test_429_count_accumulates_across_urls(): void
    {
        // Multiple URLs, each returning 429s
        // With 2 backoff delays, each URL can get up to 3 429s (1 original + 2 retries)
        // URL 1: 429, retry, 429, retry, 200 (success after 2 retries) - count = 2
        // URL 2: 429 - this is the 3rd total 429, should trigger abort
        $responses = [
            // URL 1: 429, 429, success
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body><a href="/page2">Link</a></body></html>'),
            // URL 2: 429 - this should trigger abort (3rd total 429)
            new Response(429, ['Content-Type' => 'text/html'], ''),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        // With 2 backoff delays, we can retry up to 2 times per URL
        config(['scanner.rate_limit' => [
            'backoff_delays' => [10, 10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 3,
        ]]);

        $config = $this->createConfig(['maxUrls' => 10, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertTrue($crawlResult['aborted']);
        $this->assertEquals('Scan aborted due to rate limiting', $crawlResult['error']);
    }

    public function test_crawl_continues_when_max_429_is_zero(): void
    {
        // Setting max_429_before_abort to 0 disables abort
        $responses = [
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(429, ['Content-Type' => 'text/html'], ''),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Success</body></html>'),
        ];

        $client = $this->createMockClient($responses);
        $scannerService = new ScannerService();
        $sitemapService = new SitemapService();
        $crawler = new CrawlerService($scannerService, $sitemapService);
        $crawler->setClient($client);

        config(['scanner.rate_limit' => [
            'backoff_delays' => [10, 10, 10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 0, // Disable abort
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertFalse($crawlResult['aborted']);
        $this->assertNull($crawlResult['error']);
        $this->assertEquals(200, $crawlResult['results'][0]['status']);
    }
}

