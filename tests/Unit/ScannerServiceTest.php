<?php

namespace Tests\Unit;

use App\Services\BrowsershotFetcher;
use App\Services\ScannerService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ScannerServiceTest extends TestCase
{
    private ScannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScannerService();
    }

    /**
     * Create a mock HTTP client with a predefined response.
     *
     * @param  int     $statusCode   The HTTP status code to return.
     * @param  string  $body         The response body content.
     * @param  array   $headers      Additional headers.
     * @return Client
     */
    private function createMockClient(int $statusCode, string $body = '', array $headers = []): Client
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn($body);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturnCallback(function ($name) use ($headers) {
            return $headers[$name] ?? '';
        });

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        return $mockClient;
    }

    // ===================
    // normalizeUrl tests
    // ===================

    public function test_normalize_url_returns_null_for_empty_string(): void
    {
        $result = $this->service->normalizeUrl('', 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_returns_null_for_null(): void
    {
        $result = $this->service->normalizeUrl(null, 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_removes_fragment(): void
    {
        $result = $this->service->normalizeUrl('https://example.com/page#section', 'https://example.com');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_returns_null_for_fragment_only(): void
    {
        $result = $this->service->normalizeUrl('#section', 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_handles_protocol_relative_urls(): void
    {
        $result = $this->service->normalizeUrl('//cdn.example.com/script.js', 'https://example.com');
        $this->assertEquals('https://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_absolute_urls(): void
    {
        $result = $this->service->normalizeUrl('https://other.com/page', 'https://example.com');
        $this->assertEquals('https://other.com/page', $result);
    }

    public function test_normalize_url_handles_absolute_path(): void
    {
        $result = $this->service->normalizeUrl('/about', 'https://example.com/page');
        $this->assertEquals('https://example.com/about', $result);
    }

    public function test_normalize_url_handles_relative_path(): void
    {
        $result = $this->service->normalizeUrl('contact', 'https://example.com/pages/about');
        $this->assertEquals('https://example.com/pages/contact', $result);
    }

    public function test_normalize_url_removes_trailing_slash(): void
    {
        $result = $this->service->normalizeUrl('https://example.com/page/', 'https://example.com');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_preserves_port(): void
    {
        $result = $this->service->normalizeUrl('/page', 'https://example.com:8080');
        $this->assertEquals('https://example.com:8080/page', $result);
    }

    public function test_normalize_url_handles_http_protocol(): void
    {
        $result = $this->service->normalizeUrl('//cdn.example.com/script.js', 'http://example.com');
        $this->assertEquals('http://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_query_string(): void
    {
        $result = $this->service->normalizeUrl('/search?q=test', 'https://example.com');
        $this->assertEquals('https://example.com/search?q=test', $result);
    }

    public function test_normalize_url_removes_fragment_but_keeps_query(): void
    {
        $result = $this->service->normalizeUrl('/page?foo=bar#section', 'https://example.com');
        $this->assertEquals('https://example.com/page?foo=bar', $result);
    }

    // ==========================
    // canonicalUrlKey tests
    // ==========================

    public function test_canonical_url_key_strips_trailing_slash(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/page/');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_strips_fragment(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/page#section');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_strips_tracking_params(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/page?utm_source=test&valid=1');
        $this->assertEquals('https://example.com/page?valid=1', $result);
    }

    public function test_canonical_url_key_strips_all_tracking_params(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/page?utm_source=test&fbclid=abc');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_lowercases_host(): void
    {
        $result = $this->service->canonicalUrlKey('https://Example.COM/Page');
        $this->assertEquals('https://example.com/Page', $result);
    }

    public function test_canonical_url_key_preserves_path_case(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/About/Contact');
        $this->assertEquals('https://example.com/About/Contact', $result);
    }

    public function test_canonical_url_key_lowercases_scheme_host(): void
    {
        $result = $this->service->canonicalUrlKey('HTTPS://EXAMPLE.COM/page');
        $this->assertEquals('HTTPS://example.com/page', $result);
    }

    public function test_canonical_url_key_handles_combined_normalization(): void
    {
        // Fragment + trailing slash + tracking params + mixed-case host
        $result = $this->service->canonicalUrlKey('https://Example.COM/page/?utm_source=test&fbclid=abc#section');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_preserves_port(): void
    {
        $result = $this->service->canonicalUrlKey('https://Example.COM:8080/page/');
        $this->assertEquals('https://example.com:8080/page', $result);
    }

    public function test_canonical_url_key_preserves_non_tracking_query_params(): void
    {
        $result = $this->service->canonicalUrlKey('https://example.com/search?q=test&page=2');
        $this->assertEquals('https://example.com/search?q=test&page=2', $result);
    }

    public function test_canonical_url_key_equivalent_urls_produce_same_key(): void
    {
        $key1 = $this->service->canonicalUrlKey('https://Example.com/page/');
        $key2 = $this->service->canonicalUrlKey('https://example.com/page');
        $key3 = $this->service->canonicalUrlKey('https://example.com/page#section');
        $key4 = $this->service->canonicalUrlKey('https://example.com/page?utm_source=google');

        $this->assertEquals($key1, $key2);
        $this->assertEquals($key2, $key3);
        $this->assertEquals($key3, $key4);
    }

    // ====================
    // isInternalUrl tests
    // ====================

    public function test_is_internal_url_returns_true_for_same_host(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_subdomain(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://www.example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_deep_subdomain(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://blog.www.example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_false_for_different_host(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://other.com/page');
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_false_for_similar_domain(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://notexample.com/page');
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_true_for_relative_url(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_handles_different_ports(): void
    {
        $this->service->setBaseUrl('https://example.com');
        $result = $this->service->isInternalUrl('https://example.com:8080/page');
        $this->assertTrue($result);
    }

    // =====================
    // filterResults tests
    // =====================

    public function test_filter_results_returns_all_for_all_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->service->filterResults($results, 'all');
        $this->assertCount(3, $result);
    }

    public function test_filter_results_returns_only_ok_for_ok_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->service->filterResults($results, 'ok');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertTrue($item['isOk']);
        }
    }

    public function test_filter_results_returns_only_broken_for_broken_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->service->filterResults($results, 'broken');
        $this->assertCount(1, $result);
        foreach ($result as $item) {
            $this->assertFalse($item['isOk']);
        }
    }

    public function test_filter_results_handles_empty_results(): void
    {
        $result = $this->service->filterResults([], 'all');
        $this->assertCount(0, $result);
    }

    // ======================
    // filterByElement tests
    // ======================

    public function test_filter_by_element_returns_all_for_all_filter(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->service->filterByElement($results, 'all');
        $this->assertCount(4, $result);
    }

    public function test_filter_by_element_returns_only_anchors(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->service->filterByElement($results, 'a');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('a', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_images(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->service->filterByElement($results, 'img');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('img', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_scripts(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->service->filterByElement($results, 'script');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('script', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_links(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'link'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'link'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->service->filterByElement($results, 'link');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('link', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_handles_missing_source_element(): void
    {
        $results = [
            ['url' => 'https://example.com/1'],  // No sourceElement, defaults to 'a'
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'a'],
        ];

        $result = $this->service->filterByElement($results, 'a');
        $this->assertCount(2, $result);
    }

    public function test_filter_by_element_handles_empty_results(): void
    {
        $result = $this->service->filterByElement([], 'img');
        $this->assertCount(0, $result);
    }

    // ======================
    // calculateStats tests
    // ======================

    public function test_calculate_stats_counts_correctly(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://example.com/redirect'], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 404, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 500, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 'Timeout', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
        ];

        $stats = $this->service->calculateStats($results);

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(1, $stats['ok']);        // 200 without redirects
        $this->assertEquals(1, $stats['redirects']); // 200 with redirects
        $this->assertEquals(2, $stats['broken']);    // 404 + 500
        $this->assertEquals(1, $stats['timeouts']);  // Timeout
        $this->assertEquals(0, $stats['redirectChainCount']); // single redirect is not a chain
        $this->assertEquals(1, $stats['totalRedirectHops']); // 1 hop
        $this->assertEquals(0, $stats['httpsDowngrades']); // no downgrades
    }

    public function test_calculate_stats_handles_empty_results(): void
    {
        $stats = $this->service->calculateStats([]);

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['ok']);
        $this->assertEquals(0, $stats['redirects']);
        $this->assertEquals(0, $stats['broken']);
        $this->assertEquals(0, $stats['timeouts']);
        $this->assertEquals(0, $stats['redirectChainCount']);
        $this->assertEquals(0, $stats['totalRedirectHops']);
        $this->assertEquals(0, $stats['httpsDowngrades']);
    }

    public function test_calculate_stats_counts_multiple_redirects(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://a.com', 'https://b.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://c.com'], 'hasHttpsDowngrade' => false],
        ];

        $stats = $this->service->calculateStats($results);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(0, $stats['ok']);
        $this->assertEquals(2, $stats['redirects']);
        $this->assertEquals(1, $stats['redirectChainCount']); // only 1 has 2+ hops
        $this->assertEquals(3, $stats['totalRedirectHops']); // 2 + 1 hops
    }

    public function test_calculate_stats_counts_https_downgrades(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://example.com'], 'hasHttpsDowngrade' => true],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://example.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://other.com'], 'hasHttpsDowngrade' => true],
        ];

        $stats = $this->service->calculateStats($results);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['httpsDowngrades']);
        $this->assertEquals(0, $stats['redirectChainCount']);
        $this->assertEquals(3, $stats['totalRedirectHops']);
    }

    // ======================
    // extractLinks tests
    // ======================

    public function test_extract_links_finds_href_links(): void
    {
        $html = '<html><body><a href="https://example.com/page1">Link 1</a><a href="/page2">Link 2</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(2, $links);
    }

    public function test_extract_links_skips_javascript_links(): void
    {
        $html = '<html><body><a href="javascript:void(0)">JS Link</a><a href="https://example.com/page">Normal</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/page', $links[0]['url']);
    }

    public function test_extract_links_skips_mailto_links(): void
    {
        $html = '<html><body><a href="mailto:test@example.com">Email</a><a href="/page">Page</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_skips_tel_links(): void
    {
        $html = '<html><body><a href="tel:1234567890">Phone</a><a href="/page">Page</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_skips_fragment_only_links(): void
    {
        $html = '<html><body><a href="#section">Anchor</a><a href="/page">Page</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_normalizes_relative_urls(): void
    {
        $html = '<html><body><a href="/page">Link</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/page', $links[0]['url']);
    }

    public function test_extract_links_includes_source(): void
    {
        $html = '<html><body><a href="/page">Link</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com/source');

        $this->assertEquals('https://example.com/source', $links[0]['source']);
    }

    public function test_extract_links_finds_link_href(): void
    {
        $html = '<html><head><link href="/css/style.css" rel="stylesheet"></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/css/style.css', $links[0]['url']);
        $this->assertEquals('link', $links[0]['element']);
    }

    public function test_extract_links_finds_script_src(): void
    {
        $html = '<html><head><script src="/js/app.js"></script></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/js/app.js', $links[0]['url']);
        $this->assertEquals('script', $links[0]['element']);
    }

    public function test_extract_links_finds_img_src(): void
    {
        $html = '<html><body><img src="/images/logo.png" alt="Logo"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/images/logo.png', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_img_srcset(): void
    {
        $html = '<html><body><img srcset="/images/logo-320w.jpg 320w, /images/logo-480w.jpg 480w, /images/logo-800w.jpg 800w"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(3, $links);
        $this->assertContains('https://example.com/images/logo-320w.jpg', $urls);
        $this->assertContains('https://example.com/images/logo-480w.jpg', $urls);
        $this->assertContains('https://example.com/images/logo-800w.jpg', $urls);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_img_srcset_with_pixel_density(): void
    {
        $html = '<html><body><img srcset="/images/logo.jpg 1x, /images/logo@2x.jpg 2x"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/logo.jpg', $urls);
        $this->assertContains('https://example.com/images/logo@2x.jpg', $urls);
    }

    public function test_extract_links_finds_img_data_src(): void
    {
        $html = '<html><body><img data-src="/images/lazy-loaded.jpg" class="lazy"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/images/lazy-loaded.jpg', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_picture_source_srcset(): void
    {
        $html = '<html><body>
            <picture>
                <source srcset="/images/hero-wide.jpg 1200w, /images/hero-medium.jpg 800w" media="(min-width: 800px)">
                <source srcset="/images/hero-narrow.jpg 400w">
                <img src="/images/hero-fallback.jpg">
            </picture>
        </body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertContains('https://example.com/images/hero-wide.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-medium.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-narrow.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-fallback.jpg', $urls);
    }

    public function test_extract_links_avoids_duplicates_in_srcset(): void
    {
        $html = '<html><body><img src="/images/logo.jpg" srcset="/images/logo.jpg 1x, /images/logo@2x.jpg 2x"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        // Should have 3 images: logo.jpg from src, logo.jpg from srcset (dedupe handled), logo@2x.jpg from srcset
        // Actually src adds one, then srcset checks for duplicates
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/logo.jpg', $urls);
        $this->assertContains('https://example.com/images/logo@2x.jpg', $urls);
    }

    public function test_extract_links_handles_srcset_with_empty_candidates(): void
    {
        $html = '<html><body><img srcset="/images/small.jpg 320w, , /images/large.jpg 800w"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/small.jpg', $urls);
        $this->assertContains('https://example.com/images/large.jpg', $urls);
    }

    public function test_extract_links_skips_data_urls(): void
    {
        $html = '<html><body><img src="data:image/png;base64,abc123" alt="Data Image"><img src="/real.png"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/real.png', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_all_element_types(): void
    {
        $html = '<html>
            <head>
                <link href="/style.css" rel="stylesheet">
                <script src="/app.js"></script>
            </head>
            <body>
                <a href="/page">Link</a>
                <img src="/image.png">
                <video src="/video.mp4"></video>
            </body>
        </html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $elements = array_column($links, 'element');
        $this->assertCount(5, $links);
        $this->assertContains('https://example.com/style.css', $urls);
        $this->assertContains('https://example.com/app.js', $urls);
        $this->assertContains('https://example.com/page', $urls);
        $this->assertContains('https://example.com/image.png', $urls);
        $this->assertContains('https://example.com/video.mp4', $urls);
        $this->assertContains('a', $elements);
        $this->assertContains('link', $elements);
        $this->assertContains('script', $elements);
        $this->assertContains('img', $elements);
        $this->assertContains('media', $elements);
    }

    public function test_extract_links_finds_video_src(): void
    {
        $html = '<html><body><video src="/video.mp4"></video></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/video.mp4', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_video_poster(): void
    {
        $html = '<html><body><video poster="/poster.jpg"></video></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/poster.jpg', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_audio_src(): void
    {
        $html = '<html><body><audio src="/audio.mp3"></audio></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/audio.mp3', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_video_source_src(): void
    {
        $html = '<html><body><video><source src="/video.webm" type="video/webm"></video></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/video.webm', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_audio_source_src(): void
    {
        $html = '<html><body><audio><source src="/audio.ogg" type="audio/ogg"></audio></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/audio.ogg', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_object_data(): void
    {
        $html = '<html><body><object data="/document.pdf" type="application/pdf"></object></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/document.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_embed_src(): void
    {
        $html = '<html><body><embed src="/flash.swf" type="application/x-shockwave-flash"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/flash.swf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_filter_by_element_returns_only_media(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'media'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'media'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->service->filterByElement($results, 'media');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('media', $item['sourceElement']);
        }
    }

    public function test_extract_links_finds_a_download(): void
    {
        $html = '<html><body><a href="/report.pdf" download>Download Report</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        // Should appear as both 'a' (from a[href]) and 'media' (from a[download][href])
        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertNotEmpty($mediaLinks);
        $this->assertEquals('https://example.com/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_finds_button_data_href(): void
    {
        $html = '<html><body><button data-href="/download/file.zip">Download</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/download/file.zip', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_url(): void
    {
        $html = '<html><body><div data-url="/assets/brochure.pdf" class="download-btn">Get PDF</div></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/assets/brochure.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_download(): void
    {
        $html = '<html><body><button data-download="/files/report.xlsx">Export</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/files/report.xlsx', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_file(): void
    {
        $html = '<html><body><span data-file="/docs/manual.pdf">Manual</span></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/docs/manual.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_does_not_double_classify_img_data_src(): void
    {
        $html = '<html><body><img data-src="/images/lazy.jpg" class="lazy"></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        // Should only appear once as 'img', not also as 'media'
        $this->assertCount(1, $links);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_window_location_href(): void
    {
        $html = '<html><body><button onclick="window.location.href=\'/downloads/report.pdf\'">Download</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/downloads/report.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_location_href(): void
    {
        $html = '<html><body><button onclick="location.href=\'/file.zip\'">Download</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/file.zip', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_window_open(): void
    {
        $html = '<html><body><button onclick="window.open(\'/docs/manual.pdf\')">Open</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/docs/manual.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_download_function(): void
    {
        $html = '<html><body><button onclick="download(\'/assets/cv.pdf\')">Download CV</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/assets/cv.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_onclick_skips_javascript_urls(): void
    {
        $html = '<html><body><button onclick="javascript:void(0)">No-op</button></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(0, $links);
    }

    public function test_extract_links_finds_onclick_with_double_quotes(): void
    {
        $html = "<html><body><button onclick='window.location.href=\"/report.xlsx\"'>Export</button></body></html>";

        $links = $this->service->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/report.xlsx', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    // ==========================================
    // extractLinks inline script scanning tests
    // ==========================================

    public function test_extract_links_finds_download_url_in_inline_script_when_enabled(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/files/cv.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_ignores_inline_script_when_disabled(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', false);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_ignores_inline_script_by_default(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_finds_download_url_in_next_data_script(): void
    {
        $html = '<html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"downloadUrl":"/docs/report.xlsx"}}</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/docs/report.xlsx', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_inline_script_ignores_non_download_paths(): void
    {
        $html = '<html><body><script>var api = "/api/users"; var page = "/about";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_inline_script_requires_path_prefix(): void
    {
        $html = '<html><body><script>var x = "version.pdf"; var y = "file.zip";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_inline_script_finds_multiple_files(): void
    {
        $html = '<html><body><script>
            var cv = "/files/cv.pdf";
            var brochure = "/downloads/brochure.docx";
            var archive = "/assets/data.zip";
        </script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $urls = array_column($mediaLinks, 'url');
        $this->assertCount(3, $mediaLinks);
        $this->assertContains('https://example.com/files/cv.pdf', $urls);
        $this->assertContains('https://example.com/downloads/brochure.docx', $urls);
        $this->assertContains('https://example.com/assets/data.zip', $urls);
    }

    public function test_extract_links_inline_script_finds_absolute_urls(): void
    {
        $html = '<html><body><script>var file = "https://cdn.example.com/files/report.pdf";</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://cdn.example.com/files/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_inline_script_deduplicates_urls(): void
    {
        $html = '<html><body><script>
            var a = "/files/cv.pdf";
            var b = "/files/cv.pdf";
        </script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
    }

    public function test_extract_links_inline_script_skips_script_with_src(): void
    {
        $html = '<html><head><script src="/app.js">var cv = "/files/cv.pdf";</script></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        // Should find the src as 'script' element, but NOT the inline content
        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);

        $scriptLinks = array_filter($links, fn($l) => $l['element'] === 'script');
        $this->assertCount(1, $scriptLinks);
    }

    public function test_extract_links_inline_script_finds_json_escaped_urls(): void
    {
        // Real-world pattern: JSON with forward-slash escaping (common in JSON-LD and __NEXT_DATA__)
        $html = '<html><body><script type="application/json">{"file":"\/downloads\/report.pdf"}</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/downloads/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    // ============================================
    // extractLinks external JS bundle scan tests
    // ============================================

    public function test_extract_links_scans_external_js_bundle_when_enabled(): void
    {
        // Simulate a React app where the download URL is inside the compiled JS bundle
        $bundleContent = 'onClick:()=>{const e=document.createElement("a");e.href="/cv.pdf",e.download="CV.pdf",e.click()}';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/assets/bundle.js"></script></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $this->assertNotEmpty($mediaLinks);
        $urls = array_column($mediaLinks, 'url');
        $this->assertContains('https://example.com/cv.pdf', $urls);
    }

    public function test_extract_links_does_not_scan_external_js_bundle_when_disabled(): void
    {
        $bundleContent = 'var file = "/downloads/report.pdf";';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/assets/bundle.js"></script></head><body></body></html>';

        // scanScriptContent = false (default)
        $links = $this->service->extractLinks($html, 'https://example.com', false);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_skips_external_js_bundle_from_other_domains(): void
    {
        // External CDN script should NOT be fetched
        $this->service->setBaseUrl('https://example.com');

        $html = '<html><head><script src="https://cdn.other.com/vendor.js"></script></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_external_bundle_finds_multiple_download_urls(): void
    {
        $bundleContent = 'var cv="/files/cv.pdf"; var brochure="/docs/brochure.docx"; var api="/api/users";';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/app.js"></script></head><body></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $urls = array_column($mediaLinks, 'url');
        $this->assertContains('https://example.com/files/cv.pdf', $urls);
        $this->assertContains('https://example.com/docs/brochure.docx', $urls);
        // /api/users should NOT be found (no download extension)
        $this->assertNotContains('https://example.com/api/users', $urls);
    }

    // ======================
    // followRedirects tests
    // ======================

    public function test_follow_redirects_returns_final_status(): void
    {
        $mockClient = $this->createMockClient(200, '<html></html>');
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertEquals(200, $result['finalStatus']);
        $this->assertEmpty($result['chain']);
        $this->assertFalse($result['loop']);
    }

    public function test_follow_redirects_handles_timeout(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            new ConnectException('Connection timed out', new Request('GET', 'https://example.com'))
        );
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertEquals('Timeout', $result['finalStatus']);
    }

    public function test_follow_redirects_builds_redirect_chain(): void
    {
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/redirected' : '';
        });

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($this->createMock(StreamInterface::class));

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertEquals(200, $result['finalStatus']);
        $this->assertCount(1, $result['chain']);
        $this->assertContains('https://example.com/redirected', $result['chain']);
    }

    public function test_follow_redirects_detects_https_downgrade(): void
    {
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'http://example.com/insecure' : '';
        });

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($this->createMock(StreamInterface::class));

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertTrue($result['hasHttpsDowngrade']);
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_detects_loop_to_original_url(): void
    {
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com' : '';
        });

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturn($response1);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertTrue($result['loop']);
        $this->assertCount(1, $result['chain']);
        $this->assertStringContainsString('(LOOP)', $result['chain'][0]);
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_detects_loop_in_chain(): void
    {
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/page-a' : '';
        });

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(301);
        $response2->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/page-b' : '';
        });

        $response3 = $this->createMock(ResponseInterface::class);
        $response3->method('getStatusCode')->willReturn(301);
        $response3->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/page-a' : '';
        });

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2, $response3);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertTrue($result['loop']);
        $this->assertCount(3, $result['chain']);
        $this->assertEquals('https://example.com/page-a', $result['chain'][0]);
        $this->assertEquals('https://example.com/page-b', $result['chain'][1]);
        $this->assertStringContainsString('https://example.com/page-a', $result['chain'][2]);
        $this->assertStringContainsString('(LOOP)', $result['chain'][2]);
    }

    public function test_follow_redirects_trailing_slash_redirect_is_not_a_loop(): void
    {
        // Redirect from /page to /page/ is a real redirect, not a loop
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/page/' : '';
        });

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($this->createMock(StreamInterface::class));

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/page', 'GET');

        // Should NOT be a loop - this is a valid redirect
        $this->assertFalse($result['loop']);
        $this->assertCount(1, $result['chain']);
        $this->assertEquals('https://example.com/page/', $result['chain'][0]);
        $this->assertEquals(200, $result['finalStatus']);
    }

    // ======================
    // processInternalUrl tests
    // ======================

    public function test_process_internal_url_returns_result_with_extracted_links(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        $this->assertEquals('https://example.com', $result['url']);
        $this->assertEquals('start', $result['sourcePage']);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals('internal', $result['type']);
        $this->assertTrue($result['isOk']);
        $this->assertArrayHasKey('extractedLinks', $result);
    }

    // ======================
    // processExternalUrl tests
    // ======================

    public function test_process_external_url_returns_result(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals('https://external.com/page', $result['url']);
        $this->assertEquals('https://example.com', $result['sourcePage']);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals('external', $result['type']);
        $this->assertTrue($result['isOk']);
        $this->assertArrayNotHasKey('extractedLinks', $result);
    }

    // ======================
    // Setter/Getter tests
    // ======================

    public function test_set_and_get_base_url(): void
    {
        $this->service->setBaseUrl('https://example.com/path/');

        $this->assertEquals('https://example.com/path', $this->service->getBaseUrl());
        $this->assertEquals('example.com', $this->service->getBaseHost());
    }

    public function test_set_base_url_normalizes_www(): void
    {
        $this->service->setBaseUrl('https://www.example.com/path/');

        // Should normalize to non-www version
        $this->assertEquals('https://example.com/path', $this->service->getBaseUrl());
        $this->assertEquals('example.com', $this->service->getBaseHost());
    }

    public function test_is_internal_url_matches_www_and_non_www(): void
    {
        $this->service->setBaseUrl('https://www.example.com');

        // Both www and non-www should be considered internal
        $this->assertTrue($this->service->isInternalUrl('https://example.com/page'));
        $this->assertTrue($this->service->isInternalUrl('https://www.example.com/page'));
    }

    public function test_is_internal_url_matches_non_www_base_with_www_url(): void
    {
        $this->service->setBaseUrl('https://example.com');

        // www version should also be considered internal
        $this->assertTrue($this->service->isInternalUrl('https://www.example.com/page'));
        $this->assertTrue($this->service->isInternalUrl('https://example.com/page'));
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_excludes_www_only_redirect_from_chain(): void
    {
        // Simulate www to non-www redirect
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/' : '';
        });

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('<html></html>');

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockStream);
        $response2->method('getHeaderLine')->willReturn('');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://www.example.com/', 'GET');

        // www-only redirect should NOT be in the chain
        $this->assertEmpty($result['chain']);
        $this->assertEquals(200, $result['finalStatus']);
        $this->assertFalse($result['loop']); // Should NOT be detected as a loop
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_excludes_non_www_to_www_redirect_from_chain(): void
    {
        // Simulate non-www to www redirect (reverse direction)
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://www.example.com/' : '';
        });

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('<html></html>');

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockStream);
        $response2->method('getHeaderLine')->willReturn('');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/', 'GET');

        // www-only redirect should NOT be in the chain (either direction)
        $this->assertEmpty($result['chain']);
        $this->assertEquals(200, $result['finalStatus']);
        $this->assertFalse($result['loop']);
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_includes_non_www_redirects_in_chain(): void
    {
        // Simulate redirect to a different path (not www-only)
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/new-page' : '';
        });

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('<html></html>');

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockStream);
        $response2->method('getHeaderLine')->willReturn('');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/old-page', 'GET');

        // Non-www redirect SHOULD be in the chain
        $this->assertCount(1, $result['chain']);
        $this->assertEquals('https://example.com/new-page', $result['chain'][0]);
    }

    /**
     * @throws GuzzleException
     */
    public function test_follow_redirects_www_redirect_with_path_change_is_in_chain(): void
    {
        // www redirect that ALSO changes the path should be in the chain
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/new-page' : '';
        });

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('<html></html>');

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockStream);
        $response2->method('getHeaderLine')->willReturn('');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://www.example.com/old-page', 'GET');

        // This redirect changes BOTH www AND path, so it SHOULD be in the chain
        $this->assertCount(1, $result['chain']);
        $this->assertEquals('https://example.com/new-page', $result['chain'][0]);
    }

    public function test_set_max_redirects(): void
    {
        $result = $this->service->setMaxRedirects(10);

        $this->assertSame($this->service, $result); // Fluent interface
    }

    public function test_set_client(): void
    {
        $mockClient = $this->createMock(Client::class);
        $result = $this->service->setClient($mockClient);

        $this->assertSame($this->service, $result); // Fluent interface
    }

    // ================================
    // setBrowsershotFetcher tests
    // ================================

    public function test_set_browsershot_fetcher_returns_fluent_interface(): void
    {
        $fetcher = $this->createMock(BrowsershotFetcher::class);
        $result = $this->service->setBrowsershotFetcher($fetcher);

        $this->assertSame($this->service, $result);
    }

    public function test_set_browsershot_fetcher_accepts_null(): void
    {
        $result = $this->service->setBrowsershotFetcher(null);

        $this->assertSame($this->service, $result);
    }

    // ==========================================
    // processInternalUrl with JS rendering tests
    // ==========================================

    public function test_process_internal_url_uses_browsershot_when_set(): void
    {
        // Guzzle returns a minimal SPA shell (no links in raw HTML)
        $spaShell = '<html><body><div id="root"></div></body></html>';
        $mockClient = $this->createMockClient(200, $spaShell);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Browsershot returns the rendered HTML with actual content
        $renderedHtml = '<html><body><div id="root"><a href="/about">About</a><img src="/logo.png" /></div></body></html>';
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 200,
            'body' => $renderedHtml,
            'finalUrl' => 'https://example.com',
        ]);

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        $this->assertEquals(200, $result['status']);
        $this->assertTrue($result['isOk']);

        // Should have extracted links from the rendered HTML, not the SPA shell
        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/about', $extractedUrls);
        $this->assertContains('https://example.com/logo.png', $extractedUrls);
    }

    public function test_process_internal_url_without_browsershot_uses_guzzle_body(): void
    {
        // Guzzle returns HTML with links
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // No BrowsershotFetcher set (default behavior)
        $result = $this->service->processInternalUrl('https://example.com', 'start');

        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/page1', $extractedUrls);
    }

    public function test_process_internal_url_falls_back_to_guzzle_when_browsershot_fails(): void
    {
        // Guzzle returns HTML with a link
        $html = '<html><body><a href="/fallback-page">Fallback</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Browsershot returns an error
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 'Error',
            'body' => null,
            'finalUrl' => 'https://example.com',
            'error' => 'Chrome not found',
        ]);

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        // Should fall back to Guzzle body and still extract links
        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/fallback-page', $extractedUrls);
    }

    public function test_process_internal_url_falls_back_when_browsershot_returns_empty_body(): void
    {
        // Guzzle returns HTML with a link
        $html = '<html><body><a href="/guzzle-link">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Browsershot returns 200 but empty body
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 200,
            'body' => '',
            'finalUrl' => 'https://example.com',
        ]);

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        // Should fall back to Guzzle body
        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/guzzle-link', $extractedUrls);
    }

    public function test_process_internal_url_browsershot_not_called_on_non_200(): void
    {
        // Guzzle returns 404
        $mockClient = $this->createMockClient(404);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Browsershot should NOT be called for non-200 responses
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->expects($this->never())->method('fetch');

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com/missing', 'start');

        $this->assertEquals(404, $result['status']);
        $this->assertFalse($result['isOk']);
        $this->assertEmpty($result['extractedLinks']);
    }

    public function test_process_internal_url_browsershot_receives_final_url(): void
    {
        // Simulate a redirect: Guzzle follows to a final URL
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('<html><body></body></html>');

        // First response: redirect
        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(301);
        $response1->method('getHeaderLine')->willReturnCallback(function ($name) {
            return $name === 'Location' ? 'https://example.com/final-page' : '';
        });

        // Second response: 200 at final URL
        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockStream);
        $response2->method('getHeaderLine')->willReturn('');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnOnConsecutiveCalls($response1, $response2);

        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Verify Browsershot receives the final URL after redirect
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->expects($this->once())
            ->method('fetch')
            ->with('https://example.com/final-page')
            ->willReturn([
                'status' => 200,
                'body' => '<html><body><a href="/rendered-link">Link</a></body></html>',
                'finalUrl' => 'https://example.com/final-page',
            ]);

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com/old-page', 'start');

        $this->assertEquals(200, $result['status']);
    }

    public function test_process_internal_url_browsershot_extracts_js_rendered_images(): void
    {
        // Simulate a React SPA: raw HTML has no images
        $spaShell = '<html><head></head><body><div id="root"></div><script src="/app.js"></script></body></html>';
        $mockClient = $this->createMockClient(200, $spaShell);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // After JS execution, images appear
        $renderedHtml = '<html><body><div id="root">
            <img src="https://cdn.example.com/hero.webp" alt="Hero" />
            <img src="/assets/logo.png" alt="Logo" />
            <a href="/about">About Us</a>
        </div></body></html>';

        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->method('fetch')->willReturn([
            'status' => 200,
            'body' => $renderedHtml,
            'finalUrl' => 'https://example.com',
        ]);

        $this->service->setBrowsershotFetcher($mockFetcher);

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://cdn.example.com/hero.webp', $extractedUrls);
        $this->assertContains('https://example.com/assets/logo.png', $extractedUrls);
        $this->assertContains('https://example.com/about', $extractedUrls);
    }

    public function test_process_internal_url_browsershot_disabled_after_setting_null(): void
    {
        // Set a fetcher, then disable it
        $mockFetcher = $this->createMock(BrowsershotFetcher::class);
        $mockFetcher->expects($this->never())->method('fetch');

        $this->service->setBrowsershotFetcher($mockFetcher);
        $this->service->setBrowsershotFetcher(null); // Disable

        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        // Should use Guzzle body since fetcher was disabled
        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/page1', $extractedUrls);
    }

    // ===================
    // User-Agent tests
    // ===================

    public function test_default_client_uses_scannrbot_user_agent(): void
    {
        $service = new ScannerService();

        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $client = $clientProperty->getValue($service);

        $config = $client->getConfig('headers');
        $userAgent = $config['User-Agent'] ?? '';

        $this->assertStringContainsString('ScannrBot', $userAgent);
        $this->assertStringNotContainsString('Mozilla', $userAgent);
        $this->assertStringNotContainsString('Chrome', $userAgent);
        $this->assertStringNotContainsString('Safari', $userAgent);
    }

    // ===================
    // Retry-After header tests
    // ===================

    public function test_follow_redirects_extracts_retry_after_header_on_429(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => '5']);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertEquals(5, $result['retryAfter']);
    }

    public function test_follow_redirects_returns_null_retry_after_when_header_missing(): void
    {
        $mockClient = $this->createMockClient(429, '');
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    public function test_follow_redirects_ignores_non_numeric_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => 'Wed, 21 Oct 2025 07:28:00 GMT']);
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    public function test_follow_redirects_returns_null_retry_after_on_non_429(): void
    {
        $mockClient = $this->createMockClient(200, '<html></html>');
        $this->service->setClient($mockClient);

        $result = $this->service->followRedirects('https://example.com', 'GET');

        $this->assertEquals(200, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    public function test_process_internal_url_includes_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => '10']);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com/page', 'start');

        $this->assertEquals(429, $result['status']);
        $this->assertEquals(10, $result['retryAfter']);
    }

    public function test_extract_links_finds_form_action_urls(): void
    {
        $html = '<html><body><form action="https://formspree.io/f/abc123" method="POST"><input type="text"><button type="submit">Send</button></form></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://formspree.io/f/abc123', $formLink['url']);
        $this->assertEquals('form', $formLink['element']);
    }

    public function test_extract_links_finds_relative_form_action_urls(): void
    {
        $html = '<html><body><form action="/api/contact" method="POST"><button type="submit">Send</button></form></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
        $this->assertEquals('form', $formLink['element']);
    }

    public function test_extract_links_skips_forms_without_action(): void
    {
        $html = '<html><body><form method="POST"><button type="submit">Send</button></form><a href="/page">Link</a></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    // ======================
    // Form endpoint JS extraction tests
    // ======================

    public function test_extract_links_finds_fetch_form_endpoint_in_inline_script(): void
    {
        $html = '<html><body><script>function handleSubmit(data) { fetch("/api/contact", { method: "POST", body: JSON.stringify(data) }); }</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_finds_axios_post_form_endpoint(): void
    {
        $html = '<html><body><script>axios.post("/api/submit", formData);</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/submit', $formLink['url']);
    }

    public function test_extract_links_finds_formspree_url_in_script(): void
    {
        $html = '<html><body><script>fetch("https://formspree.io/f/xpzvqwer", { method: "POST", body: formData });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        // Should find it via both fetch pattern and formspree pattern, but deduplicated
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://formspree.io/f/xpzvqwer', $formUrls);
    }

    public function test_extract_links_finds_web3forms_url_in_script(): void
    {
        $html = '<html><body><script>fetch("https://api.web3forms.com/submit", { method: "POST" });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://api.web3forms.com/submit', $formUrls);
    }

    public function test_extract_links_finds_jquery_ajax_form_endpoint(): void
    {
        $html = '<html><body><script>$.post("/api/contact", data);</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_finds_xhr_open_form_endpoint(): void
    {
        $html = '<html><body><script>var xhr = new XMLHttpRequest(); xhr.open("POST", "/api/contact");</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_does_not_find_form_endpoints_without_js_flag(): void
    {
        $html = '<html><body><script>fetch("/api/contact", { method: "POST" });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', false);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_extract_links_deduplicates_form_endpoints(): void
    {
        $html = '<html><body><script>fetch("/api/contact", opts); fetch("/api/contact", opts);</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form' && $l['url'] === 'https://example.com/api/contact');
        $this->assertCount(1, $formLinks);
    }

    public function test_extract_links_finds_axios_without_method(): void
    {
        $html = '<html><body><script>axios("/api/submit-form", { data: formData });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/submit-form', $formLink['url']);
    }

    public function test_extract_links_ignores_telemetry_fetch_urls(): void
    {
        $html = '<html><body><script>fetch("/_spark/kv"); fetch("/_spark/loaded"); fetch("/_spark/llm"); fetch("/analytics/event");</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    /**
     * @dataProvider newFormKeywordEndpointsProvider
     */
    public function test_extract_links_finds_new_keyword_endpoints(string $endpoint, string $expectedUrl): void
    {
        $html = '<html><body><script>fetch("' . $endpoint . '", { method: "POST", body: JSON.stringify(data) });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks, "Expected form endpoint for: {$endpoint}");
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains($expectedUrl, $formUrls);
    }

    public static function newFormKeywordEndpointsProvider(): array
    {
        return [
            'send' => ['/api/send', 'https://example.com/api/send'],
            'mail' => ['/api/mail', 'https://example.com/api/mail'],
            'email' => ['/api/email', 'https://example.com/api/email'],
            'checkout' => ['/api/checkout', 'https://example.com/api/checkout'],
            'order' => ['/api/order', 'https://example.com/api/order'],
            'payment' => ['/api/payment', 'https://example.com/api/payment'],
            'donate' => ['/api/donate', 'https://example.com/api/donate'],
            'donation' => ['/api/donation', 'https://example.com/api/donation'],
            'apply' => ['/api/apply', 'https://example.com/api/apply'],
            'application' => ['/api/application', 'https://example.com/api/application'],
            'enroll' => ['/api/enroll', 'https://example.com/api/enroll'],
            'survey' => ['/api/survey', 'https://example.com/api/survey'],
            'rsvp' => ['/api/rsvp', 'https://example.com/api/rsvp'],
            'review' => ['/api/review', 'https://example.com/api/review'],
            'comment' => ['/api/comment', 'https://example.com/api/comment'],
            'reply' => ['/api/reply', 'https://example.com/api/reply'],
            'upload' => ['/api/upload', 'https://example.com/api/upload'],
            'report' => ['/api/report', 'https://example.com/api/report'],
            'claim' => ['/api/claim', 'https://example.com/api/claim'],
            'login' => ['/api/login', 'https://example.com/api/login'],
            'signin' => ['/api/signin', 'https://example.com/api/signin'],
            'sign-in' => ['/api/sign-in', 'https://example.com/api/sign-in'],
            'verify' => ['/api/verify', 'https://example.com/api/verify'],
        ];
    }

    public function test_extract_links_finds_external_contact_endpoint(): void
    {
        $html = '<html><body><script>fetch("https://app.example.com/contacts", { method: "POST", body: JSON.stringify(formData) });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://app.example.com/contacts', $formLink['url']);
    }

    public function test_extract_links_finds_subscribe_endpoint(): void
    {
        $html = '<html><body><script>fetch("/api/newsletter/subscribe", { method: "POST" });</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/newsletter/subscribe', $formLink['url']);
    }

    public function test_extract_links_finds_api_config_baseurl_with_endpoints(): void
    {
        // React pattern: config object with baseUrl + endpoints, URL built via template literal
        $html = '<html><body><script>const config={baseUrl:"https://app.example.com",endpoints:{contacts:"/api/contacts"},headers:{"Content-Type":"application/json"}};function submit(data){return fetch(`${config.baseUrl}${config.endpoints.contacts}`,{method:"POST",body:JSON.stringify(data)})}</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://app.example.com/api/contacts', $formUrls);
    }

    public function test_extract_links_finds_minified_api_config(): void
    {
        // Matches the exact minified pattern from sommeling.dev
        $html = '<html><body><script>a))}const mr={baseUrl:"https://app.sommeling.dev",endpoints:{contacts:"/api/contacts"},headers:{"Content-Type":"application/json"}};function submit(){return fetch(`${mr.baseUrl}${mr.endpoints.contacts}`,{method:"POST"})}</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://sommeling.dev', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://app.sommeling.dev/api/contacts', $formUrls);
    }

    public function test_extract_links_api_config_ignores_non_form_endpoints(): void
    {
        // baseUrl with non-form endpoints should not be picked up
        $html = '<html><body><script>const api={baseUrl:"https://api.example.com",endpoints:{users:"/api/users",analytics:"/api/analytics"}};</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_extract_links_api_config_not_detected_without_js_flag(): void
    {
        $html = '<html><body><script>const config={baseUrl:"https://app.example.com",endpoints:{contacts:"/api/contacts"}};</script></body></html>';

        $links = $this->service->extractLinks($html, 'https://example.com', false);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_process_external_url_includes_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => '15']);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals(429, $result['status']);
        $this->assertEquals(15, $result['retryAfter']);
    }

    // ======================
    // Form endpoint health check tests
    // ======================

    public function test_process_external_url_uses_post_for_form_element(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function ($options) {
                return isset($options['headers']['Content-Type'])
                    && $options['headers']['Content-Type'] === 'application/json';
            }))
            ->willReturn($this->createMockResponse(422));

        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(422, $result['status']);
        $this->assertTrue($result['isOk']);
        $this->assertEquals('form', $result['sourceElement']);
    }

    public function test_process_internal_url_uses_post_for_form_element(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->anything())
            ->willReturn($this->createMockResponse(422));

        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com/api/contact', 'https://example.com', 'form');

        $this->assertEquals(422, $result['status']);
        $this->assertTrue($result['isOk']);
        $this->assertEquals('form', $result['sourceElement']);
    }

    public function test_form_endpoint_422_is_healthy(): void
    {
        $mockClient = $this->createMockClient(422);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(422, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_400_is_healthy(): void
    {
        $mockClient = $this->createMockClient(400);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(400, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_401_is_healthy(): void
    {
        $mockClient = $this->createMockClient(401);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(401, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_405_is_healthy(): void
    {
        $mockClient = $this->createMockClient(405);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(405, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_404_is_broken(): void
    {
        $mockClient = $this->createMockClient(404);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(404, $result['status']);
        $this->assertFalse($result['isOk']);
    }

    public function test_form_endpoint_500_is_broken(): void
    {
        $mockClient = $this->createMockClient(500);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(500, $result['status']);
        $this->assertFalse($result['isOk']);
    }

    public function test_form_endpoint_200_is_healthy(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(200, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_non_form_external_url_still_uses_head(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with('HEAD', $this->anything())
            ->willReturn($this->createMockResponse(200));

        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com', 'a');

        $this->assertEquals(200, $result['status']);
    }

    /**
     * Helper to create a mock response with a given status code.
     */
    private function createMockResponse(int $statusCode, array $headers = []): ResponseInterface
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn('');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturnCallback(function ($name) use ($headers) {
            return $headers[$name] ?? '';
        });

        return $mockResponse;
    }
}

