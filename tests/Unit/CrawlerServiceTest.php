<?php

namespace Tests\Unit;

use Scannr\DTO\ScanConfig;
use Scannr\Services\BrowsershotFetcher;
use Scannr\Services\CrawlerService;
use Scannr\Services\HttpChecker;
use Scannr\Services\LinkExtractor;
use Scannr\Services\LinkFlagService;
use Scannr\Services\ScannerService;
use Scannr\Services\ScanStatistics;
use Scannr\Services\SeverityEvaluator;
use Scannr\Services\SitemapService;
use Scannr\Services\UrlNormalizer;
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
            useSmartJs: $overrides['useSmartJs'] ?? false,
            respectRobots: $overrides['respectRobots'] ?? false,
        );
    }

    /**
     * Create the services needed for testing.
     *
     * @return array{urlNormalizer: UrlNormalizer, linkFlagService: LinkFlagService, httpChecker: HttpChecker, linkExtractor: LinkExtractor, scanStatistics: ScanStatistics, scannerService: ScannerService, sitemapService: SitemapService}
     */
    private function createServices(): array
    {
        $urlNormalizer = new UrlNormalizer;
        $severityEvaluator = new SeverityEvaluator;
        $linkFlagService = new LinkFlagService($urlNormalizer, $severityEvaluator);
        $httpChecker = new HttpChecker($urlNormalizer, $linkFlagService);
        $linkExtractor = new LinkExtractor($urlNormalizer, $httpChecker, $linkFlagService);
        $scanStatistics = new ScanStatistics;
        $scannerService = new ScannerService($httpChecker, $linkExtractor, $urlNormalizer, $scanStatistics, $linkFlagService);
        $sitemapService = new SitemapService(null, $urlNormalizer);

        return compact('urlNormalizer', 'linkFlagService', 'httpChecker', 'linkExtractor', 'scanStatistics', 'scannerService', 'sitemapService');
    }

    // ==================
    // Constructor tests
    // ==================

    public function test_crawler_service_can_be_instantiated(): void
    {
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);

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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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
        // Page at depth 3 (should not be visited if maxDepth = 2)
        $html3 = '<html><body>Level 3</body></html>';

        $responses = [
            new Response(200, ['Content-Type' => 'text/html'], $html0),
            new Response(200, ['Content-Type' => 'text/html'], $html1),
            new Response(200, ['Content-Type' => 'text/html'], $html2),
            new Response(200, ['Content-Type' => 'text/html'], $html3),
        ];

        $client = $this->createMockClient($responses);
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        $externalResults = array_filter($crawlResult['results'], fn ($r) => $r['type'] === 'external');
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'baseUrl' => 'https://www.example.com',
            'maxUrls' => 10,
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'baseUrl' => 'https://www.example.com',
            'maxUrls' => 10,
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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
        $jsMessages = array_filter($messages, fn ($m) => stripos($m, 'javascript') !== false || stripos($m, 'puppeteer') !== false
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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

        $jsMessages = array_filter($messages, fn ($m) => stripos($m, 'javascript') !== false);
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

        $services = $this->createServices();

        $renderedHtml = '<html><body><div id="root"><img src="https://cdn.example.com/hero.webp" /></div></body></html>';
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 200,
            'body' => $renderedHtml,
            'finalUrl' => 'https://example.com',
        ]);

        // Use a partial mock so configureJsRendering injects the mock fetcher
        // instead of relying on stale singleton state
        $crawler = $this->getMockBuilder(CrawlerService::class)
            ->setConstructorArgs([$services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']])
            ->onlyMethods(['configureJsRendering'])
            ->getMock();
        $crawler->method('configureJsRendering')
            ->willReturnCallback(function () use ($mockFetcher, $services) {
                $services['scannerService']->setBrowsershotFetcher($mockFetcher);
            });
        $crawler->setClient($client);

        $config = $this->createConfig(['useJsRendering' => true, 'maxUrls' => 5, 'scanElements' => ['a', 'img']]);
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

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

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

        $services = $this->createServices();

        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 'Error',
            'body' => null,
            'finalUrl' => 'https://example.com',
            'error' => 'Chrome crashed',
        ]);

        // Use a partial mock so configureJsRendering injects the mock fetcher
        // instead of relying on stale singleton state
        $crawler = $this->getMockBuilder(CrawlerService::class)
            ->setConstructorArgs([$services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']])
            ->onlyMethods(['configureJsRendering'])
            ->getMock();
        $crawler->method('configureJsRendering')
            ->willReturnCallback(function () use ($mockFetcher, $services) {
                $services['scannerService']->setBrowsershotFetcher($mockFetcher);
            });
        $crawler->setClient($client);

        $config = $this->createConfig(['useJsRendering' => true, 'maxUrls' => 5]);
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
                'User-Agent' => config('scannr.user_agent', 'ScannrBot/1.0 (+https://scannr.io)'),
            ],
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        // Set low backoff for test speed
        config(['scannr.rate_limit' => [
            'backoff_delays' => [10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 10,
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertFalse($crawlResult['aborted']);
        $this->assertNull($crawlResult['error']);
        $this->assertNotEmpty($crawlResult['results']);
        $this->assertEquals('200', $crawlResult['results'][0]['status']);
    }

    public function test_crawl_aborts_after_max_429_responses(): void
    {
        // All requests return 429
        $responses = array_fill(0, 20, new Response(429, ['Content-Type' => 'text/html'], ''));

        $client = $this->createMockClient($responses);
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        // Set low backoff with enough retries to reach abort threshold
        // We have 1 URL, and with 5 backoff delays we get 6 requests (1 original + 5 retries)
        // Setting max_429_before_abort to 3 means we abort on the 3rd 429
        config(['scannr.rate_limit' => [
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        config(['scannr.rate_limit' => [
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
        $this->assertEquals('200', $crawlResult['results'][0]['status']);
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        // Set specific backoff delay
        config(['scannr.rate_limit' => [
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        // Set escalating backoff delays: 200ms, then 400ms
        config(['scannr.rate_limit' => [
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
        $this->assertEquals('200', $crawlResult['results'][0]['status']);
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        // With 2 backoff delays, we can retry up to 2 times per URL
        config(['scannr.rate_limit' => [
            'backoff_delays' => [10, 10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 3,
        ]]);

        $config = $this->createConfig(['maxUrls' => 10, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertTrue($crawlResult['aborted']);
        $this->assertEquals('Scan aborted due to rate limiting', $crawlResult['error']);
    }

    /**
     * Form actions that point to the same page (self-referencing forms) should
     * still be reported. This is common with WordPress Contact Form 7, WPForms,
     * etc., where the form posts back to the same URL with a fragment.
     *
     * Duplicate forms with the same action on the same page should be deduplicated.
     *
     * e.g., <form action="/contact/#wpcf7-f54-o1"> on /contact/
     */
    public function test_crawl_reports_self_referencing_form_actions(): void
    {
        // The contact page has two forms that post to itself (with fragments)
        // — only one form result should be recorded (deduplication)
        $contactHtml = '<html><body>'
            .'<form action="/contact/#wpcf7-f54-o1" method="post" class="wpcf7-form">'
            .'<input type="text" name="name">'
            .'<input type="submit" value="Send">'
            .'</form>'
            .'<form action="/contact/#wpcf7-f54-o1" method="post" class="wpcf7-form">'
            .'<input type="text" name="email">'
            .'<input type="submit" value="Subscribe">'
            .'</form>'
            .'</body></html>';

        $client = $this->createMockClient([
            // GET /contact/ (the page itself)
            new Response(200, ['Content-Type' => 'text/html'], $contactHtml),
            // POST /contact (the form endpoint check for self-referencing form)
            new Response(200, ['Content-Type' => 'text/html'], ''),
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig([
            'baseUrl' => 'https://example.com/contact',
            'maxUrls' => 5,
            'scanElements' => ['a', 'form'],
        ]);

        $crawlResult = $crawler->crawl($config);
        $results = $crawlResult['results'];

        // Should have 2 results: the page itself + one form endpoint (deduplicated)
        $formResults = array_filter($results, fn ($r) => $r['sourceElement'] === 'form');
        $this->assertNotEmpty($formResults, 'Self-referencing form action should be reported');
        $this->assertCount(1, $formResults, 'Duplicate self-referencing forms should be deduplicated');

        $formResult = array_values($formResults)[0];
        $this->assertEquals('https://example.com/contact', $formResult['url']);
        $this->assertEquals('form', $formResult['sourceElement']);
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
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        config(['scannr.rate_limit' => [
            'backoff_delays' => [10, 10, 10],
            'respect_retry_after' => true,
            'max_429_before_abort' => 0, // Disable abort
        ]]);

        $config = $this->createConfig(['maxUrls' => 1, 'delayMin' => 0, 'delayMax' => 0]);
        $crawlResult = $crawler->crawl($config);

        $this->assertFalse($crawlResult['aborted']);
        $this->assertNull($crawlResult['error']);
        $this->assertEquals('200', $crawlResult['results'][0]['status']);
    }

    // ==================
    // Smart JS tests
    // ==================

    public function test_smart_js_activates_when_spa_detected(): void
    {
        // SPA shell with no <a> links — triggers SPA detection
        $spaShell = '<html><body><div id="root"></div><script src="/static/js/main.js"></script></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $spaShell),
            new Response(200, ['Content-Type' => 'application/javascript'], ''),
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $messages = [];
        $config = $this->createConfig(['useSmartJs' => true, 'maxUrls' => 1]);

        $crawler->crawl(
            $config,
            null,
            function (string $message) use (&$messages) {
                $messages[] = $message;
            }
        );

        // Should have a message about smart-JS activation (or fallback warning if Puppeteer not installed)
        $smartJsMessages = array_filter($messages, fn ($m) => stripos($m, 'Smart JS') !== false || stripos($m, 'smart js') !== false
        );
        $this->assertNotEmpty($smartJsMessages, 'Should display a smart-JS status message when SPA signals detected');
    }

    public function test_smart_js_does_not_activate_on_normal_html(): void
    {
        $html = '<html><body><h1>Welcome to Our Website</h1><p>We have lots of great content here for you to explore and enjoy.</p><a href="/about">About</a><a href="/contact">Contact</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>About</body></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Contact</body></html>'),
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $messages = [];
        $config = $this->createConfig(['useSmartJs' => true, 'maxUrls' => 3]);

        $crawler->crawl(
            $config,
            null,
            function (string $message) use (&$messages) {
                $messages[] = $message;
            }
        );

        $smartJsMessages = array_filter($messages, fn ($m) => stripos($m, 'Smart JS') !== false
        );
        $this->assertEmpty($smartJsMessages, 'Should NOT display smart-JS message on normal HTML');
    }

    public function test_smart_js_disabled_when_js_flag_takes_precedence(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'useJsRendering' => true,
            'useSmartJs' => true,
        ]);

        $config = $result['config'];
        $this->assertTrue($config->useJsRendering);
        $this->assertFalse($config->useSmartJs, '--js should take precedence, disabling --smart-js');
    }

    public function test_smart_js_only_triggers_once(): void
    {
        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);

        $prop = new \ReflectionProperty($crawler, 'smartJsActivated');
        $prop->setAccessible(true);
        $this->assertFalse($prop->getValue($crawler));

        $tryMethod = new \ReflectionMethod($crawler, 'tryActivateSmartJs');
        $tryMethod->setAccessible(true);

        // Without activeConfig set, tryActivateSmartJs should return null
        $result = $tryMethod->invoke($crawler, ['rawBody' => '', 'extractedLinks' => []], '/', 'start', 'a', ['a'], [], 0);
        $this->assertNull($result, 'Should return null when activeConfig is not set');
    }

    // =========================================================
    // smart-JS array_pop regression tests
    // =========================================================

    /**
     * When smart-JS activates and Browsershot IS available, tryActivateSmartJs must
     * NOT touch $this->results.  The caller (processInternalUrlAndGetResult) appends
     * the result AFTER this method returns, so any pop here would remove an unrelated
     * prior result.
     *
     * We use an anonymous subclass to report Browsershot as available and stub the
     * JS re-fetch so the test has no real browser dependency.
     */
    public function test_smart_js_activation_does_not_corrupt_prior_results(): void
    {
        $services = $this->createServices();

        // Stub processInternalUrl so the JS re-fetch returns a predictable result
        // without needing a real Browsershot / Chrome installation.
        $jsResult = ['url' => 'https://example.com', 'status' => '200', 'element' => 'a', 'rawBody' => '', 'extractedLinks' => []];
        $scannerMock = $this->createMock(ScannerService::class);
        $scannerMock->method('processInternalUrl')->willReturn($jsResult);

        // Anonymous subclass that reports Browsershot as available.
        $crawler = new class($scannerMock, $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']) extends CrawlerService
        {
            protected function checkBrowsershotDeps(): array
            {
                return ['available' => true, 'message' => ''];
            }
        };

        // Pre-seed $this->results with a prior result to simulate an earlier URL
        // having already been stored (e.g. an external asset processed before this page).
        $priorResult = ['url' => 'https://cdn.example.com/asset.js', 'status' => '200', 'element' => 'script'];
        $resultsRef = new \ReflectionProperty($crawler, 'results');
        $resultsRef->setAccessible(true);
        $resultsRef->setValue($crawler, [$priorResult]);

        // Wire up activeConfig with useSmartJs enabled.
        $configRef = new \ReflectionProperty($crawler, 'activeConfig');
        $configRef->setAccessible(true);
        $configRef->setValue($crawler, $this->createConfig(['useSmartJs' => true]));

        // SPA shell: no navigable links + single JS bundle — triggers SPA detection.
        $spaResult = [
            'rawBody' => '<html><body><div id="root"></div><script src="/app.js"></script></body></html>',
            'extractedLinks' => [],
            'url' => 'https://example.com',
            'status' => '200',
        ];

        $tryMethod = new \ReflectionMethod($crawler, 'tryActivateSmartJs');
        $tryMethod->setAccessible(true);
        $tryMethod->invoke($crawler, $spaResult, 'https://example.com', 'start', 'a', ['a'], [], 0);

        $results = $resultsRef->getValue($crawler);

        $this->assertCount(1, $results, 'tryActivateSmartJs must not remove items from $this->results');
        $this->assertSame($priorResult, $results[0], 'The pre-existing result must be unchanged');
    }

    /**
     * End-to-end: crawling a single SPA page with smart-JS enabled must produce
     * exactly one result regardless of whether Browsershot is installed.
     * Before the fix, if the result had already been appended when array_pop ran
     * it could silently discard the only stored result.
     */
    public function test_smart_js_activation_stores_exactly_one_result_for_one_page(): void
    {
        $spaShell = '<html><body><div id="root"></div><script src="/static/js/main.js"></script></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $spaShell),
            // Second response consumed by the JS re-fetch when Browsershot is available.
            new Response(200, ['Content-Type' => 'text/html'], $spaShell),
        ]);

        $services = $this->createServices();

        // Anonymous subclass that reports Browsershot as available so the re-fetch
        // code path (where the old array_pop lived) is actually exercised.
        $crawler = new class($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']) extends CrawlerService
        {
            protected function checkBrowsershotDeps(): array
            {
                return ['available' => true, 'message' => ''];
            }
        };
        $crawler->setClient($client);

        $config = $this->createConfig(['useSmartJs' => true, 'maxUrls' => 1]);
        $output = $crawler->crawl($config);

        $this->assertCount(1, $output['results'], 'Exactly one result must be stored for one crawled page');
    }

    public function test_smart_js_config_serialization_round_trip(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            useSmartJs: true,
        );

        $array = $config->toArray();
        $this->assertTrue($array['useSmartJs']);

        $restored = ScanConfig::fromArray($array)['config'];
        $this->assertTrue($restored->useSmartJs);
        $this->assertFalse($restored->useJsRendering);
    }

    public function test_crawl_propagates_rel_attribute_to_result(): void
    {
        $html = '<html><head><link rel="preconnect" href="https://cdn.example.com"></head><body></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(400, [], ''), // cdn.example.com returns 400 (typical CDN root)
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        $cdnResult = collect($crawlResult['results'])->first(fn ($r) => str_contains($r['url'], 'cdn.example.com'));
        $this->assertNotNull($cdnResult, 'cdn.example.com should appear in results');
        $this->assertEquals('preconnect', $cdnResult['rel'], 'rel attribute must be propagated through the pipeline');
    }

    public function test_crawl_rel_is_null_for_anchor_links(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';

        $client = $this->createMockClient([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Page 1</body></html>'),
        ]);

        $services = $this->createServices();
        $crawler = new CrawlerService($services['scannerService'], $services['urlNormalizer'], $services['httpChecker'], $services['sitemapService']);
        $crawler->setClient($client);

        $config = $this->createConfig(['maxUrls' => 10]);
        $crawlResult = $crawler->crawl($config);

        $pageResult = collect($crawlResult['results'])->first(fn ($r) => str_contains($r['url'], 'page1'));
        $this->assertNotNull($pageResult);
        $this->assertNull($pageResult['rel'], 'rel must be null for non-link elements');
    }
}
