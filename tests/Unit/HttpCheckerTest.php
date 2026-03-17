<?php

namespace Tests\Unit;

use Scannr\Services\HttpChecker;
use Scannr\Services\LinkFlagService;
use Scannr\Services\SeverityEvaluator;
use Scannr\Services\UrlNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class HttpCheckerTest extends TestCase
{
    private HttpChecker $httpChecker;

    private UrlNormalizer $urlNormalizer;

    private LinkFlagService $linkFlagService;

    private SeverityEvaluator $severityEvaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer;
        $this->severityEvaluator = new SeverityEvaluator;
        $this->linkFlagService = new LinkFlagService($this->urlNormalizer, $this->severityEvaluator);
        $this->httpChecker = new HttpChecker($this->urlNormalizer, $this->linkFlagService);
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
    // ======================
    // followRedirects tests
    // ======================

    public function test_follow_redirects_returns_final_status(): void
    {
        $mockClient = $this->createMockClient(200, '<html></html>');
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/page', 'GET');

        // Should NOT be a loop - this is a valid redirect
        $this->assertFalse($result['loop']);
        $this->assertCount(1, $result['chain']);
        $this->assertEquals('https://example.com/page/', $result['chain'][0]);
        $this->assertEquals(200, $result['finalStatus']);
    }

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://www.example.com/', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/old-page', 'GET');

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://www.example.com/old-page', 'GET');

        // This redirect changes BOTH www AND path, so it SHOULD be in the chain
        $this->assertCount(1, $result['chain']);
        $this->assertEquals('https://example.com/new-page', $result['chain'][0]);
    }

    public function test_set_max_redirects(): void
    {
        $result = $this->httpChecker->setMaxRedirects(10);

        $this->assertSame($this->httpChecker, $result); // Fluent interface
    }

    // ===================
    // User-Agent tests
    // ===================

    public function test_default_client_uses_scannrbot_user_agent(): void
    {
        $urlNormalizer = new UrlNormalizer;
        $severityEvaluator = new SeverityEvaluator;
        $linkFlagService = new LinkFlagService($urlNormalizer, $severityEvaluator);
        $service = new HttpChecker($urlNormalizer, $linkFlagService);

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
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertEquals(5, $result['retryAfter']);
    }

    public function test_follow_redirects_returns_null_retry_after_when_header_missing(): void
    {
        $mockClient = $this->createMockClient(429, '');
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    public function test_follow_redirects_ignores_non_numeric_retry_after(): void
    {
        $mockClient = $this->createMockClient(429, '', ['Retry-After' => 'Wed, 21 Oct 2025 07:28:00 GMT']);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com/rate-limited', 'GET');

        $this->assertEquals(429, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    public function test_follow_redirects_returns_null_retry_after_on_non_429(): void
    {
        $mockClient = $this->createMockClient(200, '<html></html>');
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->followRedirects('https://example.com', 'GET');

        $this->assertEquals(200, $result['finalStatus']);
        $this->assertNull($result['retryAfter']);
    }

    // ============================
    // processFormEndpoint tests
    // ============================

    /**
     * Build a RequestException whose getResponse() returns a mock with the given status.
     */
    private function createRequestException(int $statusCode, array $headers = []): RequestException
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getHeaderLine')->willReturnCallback(fn ($name) => $headers[$name] ?? '');

        $exception = $this->createMock(RequestException::class);
        $exception->method('hasResponse')->willReturn(true);
        $exception->method('getResponse')->willReturn($mockResponse);

        return $exception;
    }

    public function test_process_form_endpoint_returns_correct_shape(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('finalUrl', $result);
        $this->assertArrayHasKey('sourcePage', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('redirect', $result);
        $this->assertArrayHasKey('analysis', $result);
        $this->assertArrayHasKey('redirect', $result);
        // hasHttpsDowngrade is now in redirect object
        $this->assertArrayHasKey('sourceElement', $result);
        $this->assertArrayHasKey('extractedLinks', $result);
        $this->assertArrayHasKey('network', $result);
        // needsVerification is now in analysis object
        // verificationReasons replaced by analysis.flags
    }

    public function test_process_form_endpoint_200_is_ok_no_verification(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('200', $result['status']);
        $this->assertContains('form_endpoint', $result['analysis']['flags']);
        // verification is now derived from analysis
        // replaced by analysis.flags
        $this->assertEquals('form', $result['sourceElement']);
        $this->assertEquals('internal', $result['type']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('healthyFormStatusProvider')]
    public function test_process_form_endpoint_healthy_statuses_are_ok(int $status): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException($status)
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals((string) $status, $result['status']);
        $this->assertContains('form_endpoint', $result['analysis']['flags'], "Status {$status} should be considered healthy");
    }

    public static function healthyFormStatusProvider(): array
    {
        return [
            'bad request' => [400],
            'unauthorized' => [401],
            'unprocessable entity' => [422],
            'too many requests' => [429],
        ];
    }

    public function test_process_form_endpoint_403_is_ok_and_needs_verification(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(403)
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('403', $result['status']);
        $this->assertContains('form_endpoint', $result['analysis']['flags']);
        $this->assertContains('bot_protection', $result['analysis']['flags']);
    }

    public function test_process_form_endpoint_405_is_ok_and_needs_verification(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(405)
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('405', $result['status']);
        $this->assertContains('form_endpoint', $result['analysis']['flags']);
        $this->assertContains('bot_protection', $result['analysis']['flags']);
    }

    public function test_process_form_endpoint_404_is_not_ok(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(404)
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/missing', 'https://example.com');

        $this->assertEquals('404', $result['status']);
        $this->assertNotEmpty($result['analysis']['flags']);
        // verification is now derived from analysis
    }

    public function test_process_form_endpoint_500_is_not_ok(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(500)
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('500', $result['status']);
        $this->assertNotEmpty($result['analysis']['flags']);
        // verification is now derived from analysis
    }

    public function test_process_form_endpoint_timeout_needs_verification(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            new ConnectException('Connection timed out', new Request('POST', 'https://example.com/submit'))
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('timeout', $result['status']);
        $this->assertContains('timeout', $result['analysis']['flags']);
    }

    public function test_process_form_endpoint_connection_error_needs_verification(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            new ConnectException('Connection refused', new Request('POST', 'https://example.com/submit'))
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('error', $result['status']);
        $this->assertContains('connection_error', $result['analysis']['flags']);
    }

    public function test_process_form_endpoint_request_exception_without_response_is_error(): void
    {
        $exception = $this->createMock(RequestException::class);
        $exception->method('hasResponse')->willReturn(false);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException($exception);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('error', $result['status']);
        $this->assertContains('connection_error', $result['analysis']['flags']);
    }

    public function test_process_form_endpoint_429_extracts_retry_after(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(429, ['Retry-After' => '30'])
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('429', $result['status']);
        $this->assertContains('form_endpoint', $result['analysis']['flags']);
        $this->assertEquals(30, $result['network']['retryAfter']);
        // verification is now derived from analysis
    }

    public function test_process_form_endpoint_429_ignores_non_numeric_retry_after(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willThrowException(
            $this->createRequestException(429, ['Retry-After' => 'Wed, 21 Oct 2025 07:28:00 GMT'])
        );
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEquals('429', $result['status']);
        $this->assertNull($result['network']['retryAfter']);
    }

    public function test_process_form_endpoint_uses_external_type(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://external.com/submit', 'https://example.com', 'external');

        $this->assertEquals('external', $result['type']);
    }

    public function test_process_form_endpoint_sets_source_page(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com/contact');

        $this->assertEquals('https://example.com/contact', $result['sourcePage']);
    }

    public function test_process_form_endpoint_redirect_chain_is_always_empty(): void
    {
        $mockClient = $this->createMockClient(200);
        $this->httpChecker->setClient($mockClient);

        $result = $this->httpChecker->processFormEndpoint('https://example.com/submit', 'https://example.com');

        $this->assertEmpty($result['redirect']['chain']);
        $this->assertFalse($result['redirect']['isLoop']);
        $this->assertFalse($result['redirect']['hasHttpsDowngrade']);
    }
}
