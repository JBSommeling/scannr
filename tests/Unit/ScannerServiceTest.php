<?php

namespace Tests\Unit;

use App\Services\ScannerService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
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
        $this->assertEquals(1, $stats['redirectChainCount']); // 1 chain
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
        $this->assertEquals(2, $stats['redirectChainCount']); // 2 chains
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
        $this->assertEquals(3, $stats['redirectChainCount']);
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
            </body>
        </html>';

        $links = $this->service->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $elements = array_column($links, 'element');
        $this->assertCount(4, $links);
        $this->assertContains('https://example.com/style.css', $urls);
        $this->assertContains('https://example.com/app.js', $urls);
        $this->assertContains('https://example.com/page', $urls);
        $this->assertContains('https://example.com/image.png', $urls);
        $this->assertContains('a', $elements);
        $this->assertContains('link', $elements);
        $this->assertContains('script', $elements);
        $this->assertContains('img', $elements);
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
}

