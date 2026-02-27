<?php

namespace Tests\Unit;

use App\DTO\ScanConfig;
use App\Services\CrawlerService;
use App\Services\ScannerService;
use App\Services\SitemapService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

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
            scanElements: $overrides['scanElements'] ?? ['a', 'link', 'script', 'img'],
            statusFilter: $overrides['statusFilter'] ?? 'all',
            elementFilter: $overrides['elementFilter'] ?? 'all',
            outputFormat: $overrides['outputFormat'] ?? 'table',
            delayMin: $overrides['delayMin'] ?? 0,
            delayMax: $overrides['delayMax'] ?? 0,
            useSitemap: $overrides['useSitemap'] ?? false,
            customTrackingParams: $overrides['customTrackingParams'] ?? [],
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
        $results = $crawler->crawl($config);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

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
        $results = $crawler->crawl($config);

        $this->assertLessThanOrEqual(2, count($results));
    }

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
        $results = $crawler->crawl($config);

        // Should have crawled: base url (depth 0), /level1 (depth 1), /level2 (depth 2)
        // Should NOT have crawled: /level3 (depth 3)
        $urls = array_column($results, 'url');
        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/level1', $urls);
        $this->assertContains('https://example.com/level2', $urls);
        $this->assertNotContains('https://example.com/level3', $urls);
    }

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
        $results = $crawler->crawl($config);

        // Should only have 2 results: base URL and /page1 (not duplicates)
        $this->assertCount(2, $results);
    }

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
        $results = $crawler->crawl($config);

        $externalResults = array_filter($results, fn($r) => $r['type'] === 'external');
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
        $results = $crawler->crawl($config);

        // Should only have results for 'a' elements
        foreach ($results as $result) {
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
        $results = $crawler->crawl($config);

        // The URL should have the tracking param stripped
        $urls = array_column($results, 'url');
        $this->assertContains('https://example.com/page1', $urls);
        $this->assertNotContains('https://example.com/page1?custom_tracker=123', $urls);
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
        $results1 = $crawler->crawl($config);

        // Second crawl should start fresh
        $results2 = $crawler->crawl($config);

        $this->assertEquals(count($results1), count($results2));
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
        $results = $crawler->crawl($config);

        // Should only have one result for the homepage (not two for www and non-www)
        $homepageResults = array_filter($results, function ($r) {
            $url = $r['url'] ?? '';
            return $url === 'https://www.example.com' || $url === 'https://example.com';
        });

        $this->assertCount(1, $homepageResults, 'Homepage should only appear once in results');
    }
}

