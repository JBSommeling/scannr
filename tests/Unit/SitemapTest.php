<?php

namespace Tests\Unit;

use App\Console\Commands\ScanSite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SitemapTest extends TestCase
{
    private ScanSite $command;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ScanSite();
        $this->reflection = new ReflectionClass($this->command);
    }

    private function invokeMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        return $method->invokeArgs($this->command, $parameters);
    }

    private function setProperty(string $propertyName, mixed $value): void
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setValue($this->command, $value);
    }

    // ===================
    // parseSitemap tests
    // ===================

    public function test_parse_sitemap_returns_zero_for_invalid_url(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        // Create a mock client
        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willThrowException(new \Exception('Connection failed'));

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);
        $this->assertEquals(0, $result);
    }

    public function test_parse_sitemap_returns_zero_for_non_200_response(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404);

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);
        $this->assertEquals(0, $result);
    }

    public function test_parse_sitemap_returns_zero_for_invalid_xml(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn('not valid xml');

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('text/plain');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);
        $this->assertEquals(0, $result);
    }

    public function test_parse_sitemap_parses_valid_sitemap_xml(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
                <url><loc>https://example.com/page3</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        $this->assertEquals(3, $result);

        // Check that URLs were added to queue
        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertCount(3, $queue);

        // Check that URLs have depth 0 (entry points)
        foreach ($queue as $item) {
            $this->assertEquals(0, $item['depth']);
            $this->assertEquals('sitemap', $item['source']);
        }
    }

    public function test_parse_sitemap_skips_already_visited_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', ['https://example.com/page1' => true]);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        // Only page2 should be added (page1 was already visited)
        $this->assertEquals(1, $result);
    }

    public function test_parse_sitemap_skips_external_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://other-domain.com/page2</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        // Only example.com URL should be added
        $this->assertEquals(1, $result);
    }

    public function test_parse_sitemap_respects_max_recursion_depth(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        // Depth > 3 should return 0 immediately
        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 4]);
        $this->assertEquals(0, $result);
    }

    public function test_parse_sitemap_handles_sitemap_index(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

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

        $mockStreamIndex = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStreamIndex->method('__toString')->willReturn($sitemapIndexXml);

        $mockStreamChild = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStreamChild->method('__toString')->willReturn($childSitemapXml);

        $mockResponseIndex = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponseIndex->method('getStatusCode')->willReturn(200);
        $mockResponseIndex->method('getBody')->willReturn($mockStreamIndex);
        $mockResponseIndex->method('getHeaderLine')->willReturn('application/xml');

        $mockResponseChild = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponseChild->method('getStatusCode')->willReturn(200);
        $mockResponseChild->method('getBody')->willReturn($mockStreamChild);
        $mockResponseChild->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url) use ($mockResponseIndex, $mockResponseChild) {
                if (str_contains($url, 'sitemap_index') || $url === 'https://example.com/sitemap.xml') {
                    return $mockResponseIndex;
                }
                return $mockResponseChild;
            });

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        // Should have found 2 URLs from the child sitemap
        $this->assertEquals(2, $result);
    }

    public function test_parse_sitemap_removes_trailing_slash_from_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1/</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertEquals('https://example.com/page1', $queue[0]['url']);
    }

    public function test_sitemap_urls_are_added_alongside_start_url(): void
    {
        $this->setProperty('baseHost', 'example.com');
        // Simulate that the start URL is already in the queue (as it would be before sitemap discovery)
        $this->setProperty('queue', [
            ['url' => 'https://example.com', 'depth' => 0, 'source' => 'start'],
        ]);
        $this->setProperty('visited', []);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        // Should have found 2 URLs from sitemap
        $this->assertEquals(2, $result);

        // Check that queue now contains both start URL and sitemap URLs
        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertCount(3, $queue);

        // First item should be the original start URL
        $this->assertEquals('https://example.com', $queue[0]['url']);
        $this->assertEquals('start', $queue[0]['source']);

        // Remaining items should be from sitemap
        $this->assertEquals('https://example.com/page1', $queue[1]['url']);
        $this->assertEquals('sitemap', $queue[1]['source']);
        $this->assertEquals(0, $queue[1]['depth']); // Sitemap URLs are entry points

        $this->assertEquals('https://example.com/page2', $queue[2]['url']);
        $this->assertEquals('sitemap', $queue[2]['source']);
        $this->assertEquals(0, $queue[2]['depth']);
    }

    public function test_sitemap_does_not_duplicate_start_url(): void
    {
        $this->setProperty('baseHost', 'example.com');
        // Start URL is in queue AND already marked as visited
        $this->setProperty('queue', [
            ['url' => 'https://example.com', 'depth' => 0, 'source' => 'start'],
        ]);
        $this->setProperty('visited', ['https://example.com' => true]);

        // Sitemap contains the start URL and another page
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com</loc></url>
                <url><loc>https://example.com/page1</loc></url>
            </urlset>';

        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($sitemapXml);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/xml');

        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $this->setProperty('client', $mockClient);

        $result = $this->invokeMethod('parseSitemap', ['https://example.com/sitemap.xml', 0]);

        // Should only add page1 (start URL is already visited)
        $this->assertEquals(1, $result);

        // Queue should have start URL + page1 (not duplicated start URL)
        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertCount(2, $queue);

        $this->assertEquals('https://example.com', $queue[0]['url']);
        $this->assertEquals('start', $queue[0]['source']);

        $this->assertEquals('https://example.com/page1', $queue[1]['url']);
        $this->assertEquals('sitemap', $queue[1]['source']);
    }

    // ===================
    // Sitemap format detection tests
    // ===================

    public function test_is_xml_content_detects_xml_content_type(): void
    {
        $result = $this->invokeMethod('isXmlContent', ['<urlset></urlset>', 'application/xml']);
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_xml_declaration(): void
    {
        $result = $this->invokeMethod('isXmlContent', ['<?xml version="1.0"?><urlset></urlset>', 'text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_urlset_element(): void
    {
        $result = $this->invokeMethod('isXmlContent', ['<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', 'text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_xml_content_detects_sitemapindex_element(): void
    {
        $result = $this->invokeMethod('isXmlContent', ['<sitemapindex></sitemapindex>', 'text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_xml_content_returns_false_for_html(): void
    {
        $result = $this->invokeMethod('isXmlContent', ['<!DOCTYPE html><html></html>', 'text/html']);
        $this->assertFalse($result);
    }

    public function test_is_html_content_detects_html_content_type(): void
    {
        $result = $this->invokeMethod('isHtmlContent', ['<div>test</div>', 'text/html; charset=utf-8']);
        $this->assertTrue($result);
    }

    public function test_is_html_content_detects_doctype(): void
    {
        $result = $this->invokeMethod('isHtmlContent', ['<!DOCTYPE html><html></html>', 'text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_html_content_detects_html_tag(): void
    {
        $result = $this->invokeMethod('isHtmlContent', ['<html><body></body></html>', 'text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_html_content_returns_false_for_xml(): void
    {
        $result = $this->invokeMethod('isHtmlContent', ['<?xml version="1.0"?><urlset></urlset>', 'application/xml']);
        $this->assertFalse($result);
    }

    public function test_is_text_content_detects_plain_text(): void
    {
        $result = $this->invokeMethod('isTextContent', ['text/plain']);
        $this->assertTrue($result);
    }

    public function test_is_text_content_returns_false_for_html(): void
    {
        $result = $this->invokeMethod('isTextContent', ['text/html']);
        $this->assertFalse($result);
    }

    // ===================
    // XML sitemap parsing tests
    // ===================

    public function test_parse_xml_sitemap_parses_valid_sitemap(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page1</loc></url>
                <url><loc>https://example.com/page2</loc></url>
            </urlset>';

        $result = $this->invokeMethod('parseXmlSitemap', [$sitemapXml, 0]);

        $this->assertEquals(2, $result);
    }

    public function test_parse_xml_sitemap_returns_zero_for_invalid_xml(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $result = $this->invokeMethod('parseXmlSitemap', ['not valid xml', 0]);

        $this->assertEquals(0, $result);
    }

    // ===================
    // HTML sitemap parsing tests
    // ===================

    public function test_parse_html_sitemap_extracts_links(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $html = '<!DOCTYPE html>
            <html>
            <body>
                <a href="https://example.com/page1">Page 1</a>
                <a href="https://example.com/page2">Page 2</a>
                <a href="/page3">Page 3</a>
            </body>
            </html>';

        $result = $this->invokeMethod('parseHtmlSitemap', [$html, 'https://example.com']);

        $this->assertEquals(3, $result);

        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertCount(3, $queue);
    }

    public function test_parse_html_sitemap_skips_external_links(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $html = '<!DOCTYPE html>
            <html>
            <body>
                <a href="https://example.com/page1">Page 1</a>
                <a href="https://other-site.com/page2">External</a>
            </body>
            </html>';

        $result = $this->invokeMethod('parseHtmlSitemap', [$html, 'https://example.com']);

        $this->assertEquals(1, $result);
    }

    public function test_parse_html_sitemap_skips_javascript_mailto_links(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

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

        $result = $this->invokeMethod('parseHtmlSitemap', [$html, 'https://example.com']);

        $this->assertEquals(1, $result);
    }

    // ===================
    // Text sitemap parsing tests
    // ===================

    public function test_parse_text_sitemap_extracts_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $text = "https://example.com/page1\nhttps://example.com/page2\nhttps://example.com/page3";

        $result = $this->invokeMethod('parseTextSitemap', [$text]);

        $this->assertEquals(3, $result);
    }

    public function test_parse_text_sitemap_handles_different_line_endings(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        // Mix of \r\n, \r, and \n
        $text = "https://example.com/page1\r\nhttps://example.com/page2\rhttps://example.com/page3\nhttps://example.com/page4";

        $result = $this->invokeMethod('parseTextSitemap', [$text]);

        $this->assertEquals(4, $result);
    }

    public function test_parse_text_sitemap_skips_comments_and_empty_lines(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $text = "# This is a comment\nhttps://example.com/page1\n\n# Another comment\nhttps://example.com/page2\n   \n";

        $result = $this->invokeMethod('parseTextSitemap', [$text]);

        $this->assertEquals(2, $result);
    }

    public function test_parse_text_sitemap_skips_invalid_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $text = "https://example.com/page1\nnot-a-valid-url\nhttps://example.com/page2\njust some text";

        $result = $this->invokeMethod('parseTextSitemap', [$text]);

        $this->assertEquals(2, $result);
    }

    public function test_parse_text_sitemap_skips_external_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $text = "https://example.com/page1\nhttps://other-site.com/page2\nhttps://example.com/page3";

        $result = $this->invokeMethod('parseTextSitemap', [$text]);

        $this->assertEquals(2, $result);
    }

    // ===================
    // addSitemapUrl tests
    // ===================

    public function test_add_sitemap_url_adds_valid_internal_url(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $result = $this->invokeMethod('addSitemapUrl', ['https://example.com/page1']);

        $this->assertEquals(1, $result);

        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertCount(1, $queue);
        $this->assertEquals('https://example.com/page1', $queue[0]['url']);
        $this->assertEquals('sitemap', $queue[0]['source']);
        $this->assertEquals(0, $queue[0]['depth']);
    }

    public function test_add_sitemap_url_removes_trailing_slash(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $this->invokeMethod('addSitemapUrl', ['https://example.com/page1/']);

        $queue = $this->reflection->getProperty('queue')->getValue($this->command);
        $this->assertEquals('https://example.com/page1', $queue[0]['url']);
    }

    public function test_add_sitemap_url_skips_visited_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', ['https://example.com/page1' => true]);

        $result = $this->invokeMethod('addSitemapUrl', ['https://example.com/page1']);

        $this->assertEquals(0, $result);
    }

    public function test_add_sitemap_url_skips_external_urls(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $this->setProperty('queue', []);
        $this->setProperty('visited', []);

        $result = $this->invokeMethod('addSitemapUrl', ['https://other-site.com/page1']);

        $this->assertEquals(0, $result);
    }
}

