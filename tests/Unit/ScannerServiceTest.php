<?php

namespace Tests\Unit;

use App\Services\BrowsershotFetcher;
use App\Services\HttpChecker;
use App\Services\LinkExtractor;
use App\Services\ScannerService;
use App\Services\ScanStatistics;
use App\Services\UrlNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ScannerServiceTest extends TestCase
{
    private ScannerService $service;
    private UrlNormalizer $urlNormalizer;
    private HttpChecker $httpChecker;
    private LinkExtractor $linkExtractor;
    private ScanStatistics $scanStatistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer();
        $this->httpChecker = new HttpChecker($this->urlNormalizer);
        $this->linkExtractor = new LinkExtractor($this->urlNormalizer, $this->httpChecker);
        $this->scanStatistics = new ScanStatistics();
        $this->service = new ScannerService(
            $this->httpChecker,
            $this->linkExtractor,
            $this->urlNormalizer,
            $this->scanStatistics,
        );
    }

    /**
     * Create a mock HTTP client with a predefined response.
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

    /**
     * Create a mock HTTP response with a predefined status code.
     */
    private function createMockResponse(int $statusCode, string $body = '', array $headers = []): ResponseInterface
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn($body);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturnCallback(function ($name) use ($headers) {
            return $headers[$name] ?? '';
        });

        return $mockResponse;
    }

    // ======================
    // processInternalUrl tests
    // ======================

    public function test_process_internal_url_returns_result_with_extracted_links(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals('https://external.com/page', $result['url']);
        $this->assertEquals('https://example.com', $result['sourcePage']);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals('external', $result['type']);
        $this->assertTrue($result['isOk']);
        $this->assertArrayNotHasKey('extractedLinks', $result);
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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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

        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com', 'start');

        // Should use Guzzle body since fetcher was disabled
        $extractedUrls = array_column($result['extractedLinks'], 'url');
        $this->assertContains('https://example.com/page1', $extractedUrls);
    }


    public function test_process_internal_url_includes_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => '10']);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com/page', 'start');

        $this->assertEquals(429, $result['status']);
        $this->assertEquals(10, $result['retryAfter']);
    }


    public function test_process_external_url_includes_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => '15']);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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

        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

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

        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl('https://example.com/api/contact', 'https://example.com', 'form');

        $this->assertEquals(422, $result['status']);
        $this->assertTrue($result['isOk']);
        $this->assertEquals('form', $result['sourceElement']);
    }

    public function test_form_endpoint_422_is_healthy(): void
    {
        $mockClient = $this->createMockClient(422);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(422, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_400_is_healthy(): void
    {
        $mockClient = $this->createMockClient(400);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(400, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_401_is_healthy(): void
    {
        $mockClient = $this->createMockClient(401);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(401, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_405_is_healthy(): void
    {
        $mockClient = $this->createMockClient(405);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(405, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_404_is_broken(): void
    {
        $mockClient = $this->createMockClient(404);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(404, $result['status']);
        $this->assertFalse($result['isOk']);
    }

    public function test_form_endpoint_500_is_broken(): void
    {
        $mockClient = $this->createMockClient(500);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(500, $result['status']);
        $this->assertFalse($result['isOk']);
    }

    public function test_form_endpoint_200_is_healthy(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertEquals(200, $result['status']);
        $this->assertTrue($result['isOk']);
    }

    public function test_form_endpoint_always_has_needs_verification_false(): void
    {
        $mockClient = $this->createMockClient(429);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://app.example.com/api/contacts', 'https://example.com', 'form');

        $this->assertArrayHasKey('needsVerification', $result);
        $this->assertFalse($result['needsVerification']);
        $this->assertArrayHasKey('verificationReason', $result);
        $this->assertNull($result['verificationReason']);
    }

    public function test_non_form_external_url_still_uses_head(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with('HEAD', $this->anything())
            ->willReturn($this->createMockResponse(200));

        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com', 'a');

        $this->assertEquals(200, $result['status']);
    }

    // ======================
    // Verification flag tests
    // ======================

    public function test_process_external_url_propagates_verification_flags(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl(
            'https://external.com/page',
            'https://example.com',
            'a',
            true,
            'js_bundle_extracted'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertTrue($result['needsVerification']);
        $this->assertEquals('js_bundle_extracted', $result['verificationReason']);
    }

    public function test_process_external_url_detects_bot_protection_403(): void
    {
        $mockClient = $this->createMockClient(403);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals(403, $result['status']);
        $this->assertTrue($result['needsVerification']);
        $this->assertEquals('bot_protection', $result['verificationReason']);
    }

    public function test_process_external_url_detects_bot_protection_405(): void
    {
        $mockClient = $this->createMockClient(405);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals(405, $result['status']);
        $this->assertTrue($result['needsVerification']);
        $this->assertEquals('bot_protection', $result['verificationReason']);
    }

    public function test_process_external_url_detects_bot_protection_timeout(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException('Connection failed', new \GuzzleHttp\Psr7\Request('HEAD', 'https://external.com')));

        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processExternalUrl('https://external.com/page', 'https://example.com');

        $this->assertEquals('Timeout', $result['status']);
        $this->assertTrue($result['needsVerification']);
        $this->assertEquals('bot_protection', $result['verificationReason']);
    }

    public function test_process_internal_url_propagates_verification_flags(): void
    {
        $html = '<html><body><a href="/page1">Link</a></body></html>';
        $mockClient = $this->createMockClient(200, $html);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->processInternalUrl(
            'https://example.com',
            'start',
            'a',
            true,
            'suspicious_dynamic_url'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertTrue($result['needsVerification']);
        $this->assertEquals('suspicious_dynamic_url', $result['verificationReason']);
    }
}


