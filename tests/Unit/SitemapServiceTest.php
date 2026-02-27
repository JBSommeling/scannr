<?php

namespace Tests\Unit;

use App\Services\SitemapService;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class SitemapServiceTest extends TestCase
{
    private SitemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SitemapService();
    }

    /**
     * Create a mock HTTP client with a predefined response.
     *
     * @param  int     $statusCode   The HTTP status code to return.
     * @param  string  $body         The response body content.
     * @param  string  $contentType  The Content-Type header value.
     * @return Client
     */
    private function createMockClient(int $statusCode, string $body, string $contentType): Client
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn($body);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn($contentType);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        return $mockClient;
    }

    /**
     * Create a mock HTTP client that throws an exception.
     *
     * @return Client
     */
    private function createExceptionClient(): Client
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(new \Exception('Connection failed'));

        return $mockClient;
    }

    // ===================
    // parseSitemap tests
    // ===================

    public function test_parse_sitemap_returns_empty_for_invalid_url(): void
    {
        $this->service->setClient($this->createExceptionClient());
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->parseSitemap('https://example.com/sitemap.xml');
        $this->assertEmpty($result);
    }

    public function test_parse_sitemap_returns_empty_for_non_200_response(): void
    {
        $mockClient = $this->createMockClient(404, '', 'text/html');
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->parseSitemap('https://example.com/sitemap.xml');
        $this->assertEmpty($result);
    }

    public function test_parse_sitemap_returns_empty_for_invalid_content(): void
    {
        $mockClient = $this->createMockClient(200, 'not valid content', 'text/plain');
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        // Text that doesn't contain valid URLs
        $result = $this->service->parseSitemap('https://example.com/sitemap.xml');
        $this->assertEmpty($result);
    }

    public function test_parse_sitemap_parses_valid_sitemap_xml(): void
    {
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
                <url><loc>https://example.com/page3</loc></url>
            </urlset>';

        $mockClient = $this->createMockClient(200, $sitemapXml, 'application/xml');
        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->parseSitemap('https://example.com/sitemap.xml');

        $this->assertCount(3, $result);
        $this->assertContains('https://example.com/page1', $result);
        $this->assertContains('https://example.com/page2', $result);
        $this->assertContains('https://example.com/page3', $result);
    }

    public function test_parse_sitemap_respects_max_recursion_depth(): void
    {
        $this->service->setBaseUrl('https://example.com');

        // Depth > 3 should return empty immediately
        $result = $this->service->parseSitemap('https://example.com/sitemap.xml', 4);
        $this->assertEmpty($result);
    }

    public function test_parse_sitemap_handles_sitemap_index(): void
    {
        // Sitemap index XML
        $sitemapIndexXml = '<?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <sitemap><loc>https://example.com/sitemap1.xml</loc></sitemap>
            </sitemapindex>';

        // Child sitemap XML
        $childSitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $mockStreamIndex = $this->createMock(StreamInterface::class);
        $mockStreamIndex->method('__toString')->willReturn($sitemapIndexXml);

        $mockStreamChild = $this->createMock(StreamInterface::class);
        $mockStreamChild->method('__toString')->willReturn($childSitemapXml);

        $mockResponseIndex = $this->createMock(ResponseInterface::class);
        $mockResponseIndex->method('getStatusCode')->willReturn(200);
        $mockResponseIndex->method('getBody')->willReturn($mockStreamIndex);
        $mockResponseIndex->method('getHeaderLine')->willReturn('application/xml');

        $mockResponseChild = $this->createMock(ResponseInterface::class);
        $mockResponseChild->method('getStatusCode')->willReturn(200);
        $mockResponseChild->method('getBody')->willReturn($mockStreamChild);
        $mockResponseChild->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($mockResponseIndex, $mockResponseChild) {
                if (str_contains($url, 'sitemap.xml') && !str_contains($url, 'sitemap1')) {
                    return $mockResponseIndex;
                }
                return $mockResponseChild;
            });

        $this->service->setClient($mockClient);
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->parseSitemap('https://example.com/sitemap.xml');

        // Should have found 2 URLs from the child sitemap
        $this->assertCount(2, $result);
    }

    // ===================
    // Sitemap format detection tests
    // ===================

    public function test_is_xml_content_detects_xml_content_type(): void
    {
        $result = $this->service->isXmlContent('<urlset></urlset>', 'application/xml');
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_xml_declaration(): void
    {
        $result = $this->service->isXmlContent('<?xml version="1.0"?><urlset></urlset>', 'text/plain');
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_urlset_element(): void
    {
        $result = $this->service->isXmlContent('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', 'text/plain');
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_sitemapindex_element(): void
    {
        $result = $this->service->isXmlContent('<sitemapindex></sitemapindex>', 'text/plain');
        $this->assertTrue($result);
    }

    public function test_is_xml_content_returns_false_for_html(): void
    {
        $result = $this->service->isXmlContent('<!DOCTYPE html><html></html>', 'text/html');
        $this->assertFalse($result);
    }

    public function test_is_html_content_detects_html_content_type(): void
    {
        $result = $this->service->isHtmlContent('<div>test</div>', 'text/html; charset=utf-8');
        $this->assertTrue($result);
    }

    public function test_is_html_content_detects_doctype(): void
    {
        $result = $this->service->isHtmlContent('<!DOCTYPE html><html></html>', 'text/plain');
        $this->assertTrue($result);
    }

    public function test_is_html_content_detects_html_tag(): void
    {
        $result = $this->service->isHtmlContent('<html><body></body></html>', 'text/plain');
        $this->assertTrue($result);
    }

    public function test_is_html_content_returns_false_for_xml(): void
    {
        $result = $this->service->isHtmlContent('<?xml version="1.0"?><urlset></urlset>', 'application/xml');
        $this->assertFalse($result);
    }

    public function test_is_text_content_detects_plain_text(): void
    {
        $result = $this->service->isTextContent('text/plain');
        $this->assertTrue($result);
    }

    public function test_is_text_content_returns_false_for_html(): void
    {
        $result = $this->service->isTextContent('text/html');
        $this->assertFalse($result);
    }

    // ===================
    // XML sitemap parsing tests
    // ===================

    public function test_parse_xml_sitemap_parses_valid_sitemap(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $result = $this->service->parseXmlSitemap($sitemapXml, 0);

        $this->assertCount(2, $result);
    }

    public function test_parse_xml_sitemap_returns_empty_for_invalid_xml(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->parseXmlSitemap('not valid xml', 0);

        $this->assertEmpty($result);
    }

    public function test_parse_xml_sitemap_without_namespace(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset>
                <url><loc>https://example.com/page1</loc></url>
            </urlset>';

        $result = $this->service->parseXmlSitemap($sitemapXml, 0);

        $this->assertCount(1, $result);
        $this->assertEquals('https://example.com/page1', $result[0]);
    }

    public function test_parse_xml_sitemap_without_urlset_wrapper(): void
    {
        $this->service->setBaseUrl('https://example.com');

        // Sitemap with only url elements and no urlset wrapper (like sommeling.dev)
        $sitemapXml = '<!-- Comment -->
            <url><loc>https://example.com/page1</loc></url>
            <url><loc>https://example.com/page2</loc></url>';

        $result = $this->service->parseXmlSitemap($sitemapXml, 0);

        $this->assertCount(2, $result);
        $this->assertContains('https://example.com/page1', $result);
        $this->assertContains('https://example.com/page2', $result);
    }

    public function test_parse_xml_sitemap_with_direct_loc_elements(): void
    {
        $this->service->setBaseUrl('https://example.com');

        // Sitemap with loc elements that can be found via fallback
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <root>
                <loc>https://example.com/page1</loc>
                <loc>https://example.com/page2</loc>
            </root>';

        $result = $this->service->parseXmlSitemap($sitemapXml, 0);

        $this->assertCount(2, $result);
    }

    // ===================
    // HTML sitemap parsing tests
    // ===================

    public function test_parse_html_sitemap_extracts_links(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $html = '<!DOCTYPE html>
            <html>
            <body>
                <a href="https://example.com/page1">Page 1</a>
                <a href="https://example.com/page2">Page 2</a>
                <a href="/page3">Page 3</a>
            </body>
            </html>';

        $result = $this->service->parseHtmlSitemap($html, 'https://example.com');

        $this->assertCount(3, $result);
    }

    public function test_parse_html_sitemap_skips_javascript_mailto_links(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $html = '<!DOCTYPE html>
            <html>
            <body>
                <a href="https://example.com/page1">Page 1</a>
                <a href="javascript:void(0)">JS Link</a>
                <a href="mailto:test@example.com">Email</a>
                <a href="tel:123456">Phone</a>
                <a href="#section">Anchor</a>
            </body>
            </html>';

        $result = $this->service->parseHtmlSitemap($html, 'https://example.com');

        $this->assertCount(1, $result);
    }

    // ===================
    // Text sitemap parsing tests
    // ===================

    public function test_parse_text_sitemap_extracts_urls(): void
    {
        $text = "https://example.com/page1\nhttps://example.com/page2\nhttps://example.com/page3";

        $result = $this->service->parseTextSitemap($text);

        $this->assertCount(3, $result);
    }

    public function test_parse_text_sitemap_handles_different_line_endings(): void
    {
        // Mix of \r\n, \r, and \n
        $text = "https://example.com/page1\r\nhttps://example.com/page2\rhttps://example.com/page3\nhttps://example.com/page4";

        $result = $this->service->parseTextSitemap($text);

        $this->assertCount(4, $result);
    }

    public function test_parse_text_sitemap_skips_comments_and_empty_lines(): void
    {
        $text = "# This is a comment\nhttps://example.com/page1\n\n# Another comment\nhttps://example.com/page2\n   \n";

        $result = $this->service->parseTextSitemap($text);

        $this->assertCount(2, $result);
    }

    public function test_parse_text_sitemap_skips_invalid_urls(): void
    {
        $text = "https://example.com/page1\nnot-a-valid-url\nhttps://example.com/page2\njust some text";

        $result = $this->service->parseTextSitemap($text);

        $this->assertCount(2, $result);
    }


    // ===================
    // isInternalUrl tests
    // ===================

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

    public function test_is_internal_url_returns_false_for_external(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->isInternalUrl('https://other-site.com/page');

        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_true_for_no_host(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->isInternalUrl('/page');

        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_non_www_when_base_has_www(): void
    {
        $this->service->setBaseUrl('https://www.example.com');

        $result = $this->service->isInternalUrl('https://example.com/page');

        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_www_when_base_has_no_www(): void
    {
        $this->service->setBaseUrl('https://example.com');

        $result = $this->service->isInternalUrl('https://www.example.com/page');

        $this->assertTrue($result);
    }

    public function test_set_base_url_normalizes_www(): void
    {
        $this->service->setBaseUrl('https://www.example.com');

        // Use reflection to check the internal baseUrl and baseHost
        $reflection = new \ReflectionClass($this->service);

        $baseUrlProp = $reflection->getProperty('baseUrl');
        $baseUrlProp->setAccessible(true);

        $baseHostProp = $reflection->getProperty('baseHost');
        $baseHostProp->setAccessible(true);

        // Should be normalized to non-www
        $this->assertEquals('https://example.com', $baseUrlProp->getValue($this->service));
        $this->assertEquals('example.com', $baseHostProp->getValue($this->service));
    }

    // ===================
    // discoverUrls tests
    // ===================

    public function test_discover_urls_returns_urls_and_count(): void
    {
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        // Mock for robots.txt (404) and sitemap.xml (success)
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($sitemapXml) {
                $mockStream = $this->createMock(StreamInterface::class);
                $mockResponse = $this->createMock(ResponseInterface::class);

                if (str_contains($url, 'robots.txt')) {
                    $mockResponse->method('getStatusCode')->willReturn(404);
                    return $mockResponse;
                }

                $mockStream->method('__toString')->willReturn($sitemapXml);
                $mockResponse->method('getStatusCode')->willReturn(200);
                $mockResponse->method('getBody')->willReturn($mockStream);
                $mockResponse->method('getHeaderLine')->willReturn('application/xml');
                return $mockResponse;
            });

        $this->service->setClient($mockClient);

        $result = $this->service->discoverUrls('https://example.com');

        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['urls']);

        // Verify URL structure
        foreach ($result['urls'] as $urlData) {
            $this->assertArrayHasKey('url', $urlData);
            $this->assertArrayHasKey('source', $urlData);
            $this->assertEquals('sitemap', $urlData['source']);
        }
    }

    public function test_discover_urls_filters_external_urls(): void
    {
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://other-site.com/page2</loc></url>
            </urlset>';

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($sitemapXml) {
                $mockStream = $this->createMock(StreamInterface::class);
                $mockResponse = $this->createMock(ResponseInterface::class);

                if (str_contains($url, 'robots.txt')) {
                    $mockResponse->method('getStatusCode')->willReturn(404);
                    return $mockResponse;
                }

                $mockStream->method('__toString')->willReturn($sitemapXml);
                $mockResponse->method('getStatusCode')->willReturn(200);
                $mockResponse->method('getBody')->willReturn($mockStream);
                $mockResponse->method('getHeaderLine')->willReturn('application/xml');
                return $mockResponse;
            });

        $this->service->setClient($mockClient);

        $result = $this->service->discoverUrls('https://example.com');

        // Should only have internal URL
        $this->assertEquals(1, $result['count']);
    }

    public function test_discover_urls_deduplicates_urls(): void
    {
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page1/</loc></url>
                <url><loc>https://example.com/page1</loc></url>
            </urlset>';

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($sitemapXml) {
                $mockStream = $this->createMock(StreamInterface::class);
                $mockResponse = $this->createMock(ResponseInterface::class);

                if (str_contains($url, 'robots.txt')) {
                    $mockResponse->method('getStatusCode')->willReturn(404);
                    return $mockResponse;
                }

                $mockStream->method('__toString')->willReturn($sitemapXml);
                $mockResponse->method('getStatusCode')->willReturn(200);
                $mockResponse->method('getBody')->willReturn($mockStream);
                $mockResponse->method('getHeaderLine')->willReturn('application/xml');
                return $mockResponse;
            });

        $this->service->setClient($mockClient);

        $result = $this->service->discoverUrls('https://example.com');

        // Should deduplicate URLs (trailing slash normalized)
        $this->assertEquals(1, $result['count']);
    }

    public function test_discover_urls_returns_empty_when_no_sitemap_found(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) {
                $mockResponse = $this->createMock(ResponseInterface::class);
                $mockResponse->method('getStatusCode')->willReturn(404);
                return $mockResponse;
            });

        $this->service->setClient($mockClient);

        $result = $this->service->discoverUrls('https://example.com');

        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['urls']);
    }

    public function test_discover_urls_finds_non_www_urls_when_base_has_www(): void
    {
        // This tests the exact scenario: scanning www.sommeling.dev but sitemap has sommeling.dev URLs
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($sitemapXml) {
                $mockStream = $this->createMock(StreamInterface::class);
                $mockResponse = $this->createMock(ResponseInterface::class);

                if (str_contains($url, 'robots.txt')) {
                    $mockResponse->method('getStatusCode')->willReturn(404);
                    return $mockResponse;
                }

                $mockStream->method('__toString')->willReturn($sitemapXml);
                $mockResponse->method('getStatusCode')->willReturn(200);
                $mockResponse->method('getBody')->willReturn($mockStream);
                $mockResponse->method('getHeaderLine')->willReturn('application/xml');
                return $mockResponse;
            });

        $this->service->setClient($mockClient);

        // Use www version as base URL
        $result = $this->service->discoverUrls('https://www.example.com');

        // Should find the non-www URLs as internal (they're the same domain)
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['urls']);
    }
}

