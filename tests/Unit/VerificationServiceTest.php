<?php

namespace Tests\Unit;

use App\DTO\VerificationStatus;
use App\Enums\VerificationReason;
use App\Services\UrlNormalizer;
use App\Services\VerificationService;
use PHPUnit\Framework\TestCase;

class VerificationServiceTest extends TestCase
{
    private VerificationService $service;
    private UrlNormalizer $urlNormalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer();
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $this->service = new VerificationService($this->urlNormalizer);
    }

    // ==================
    // detectFromHttpResponse tests
    // ==================

    public function test_detect_from_http_response_403_returns_bot_protection(): void
    {
        $status = $this->service->detectFromHttpResponse(403);

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_detect_from_http_response_405_returns_bot_protection(): void
    {
        $status = $this->service->detectFromHttpResponse(405);

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_detect_from_http_response_error_returns_bot_protection(): void
    {
        $status = $this->service->detectFromHttpResponse('Error');

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_detect_from_http_response_timeout_returns_bot_protection(): void
    {
        $status = $this->service->detectFromHttpResponse('Timeout');

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_detect_from_http_response_200_returns_none(): void
    {
        $status = $this->service->detectFromHttpResponse(200);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_detect_from_http_response_404_returns_none(): void
    {
        $status = $this->service->detectFromHttpResponse(404);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_detect_from_http_response_500_returns_none(): void
    {
        $status = $this->service->detectFromHttpResponse(500);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_detect_from_http_response_301_returns_none(): void
    {
        $status = $this->service->detectFromHttpResponse(301);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    // ==================
    // detectFromJsBundle tests
    // ==================

    public function test_detect_from_js_bundle_internal_without_suspicious_syntax_returns_none(): void
    {
        $status = $this->service->detectFromJsBundle(
            'https://example.com/page',
            hasSuspiciousSyntax: false,
            isInternal: true
        );

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_detect_from_js_bundle_with_suspicious_syntax_returns_indirect_reference(): void
    {
        $status = $this->service->detectFromJsBundle(
            'https://example.com/api/${id}',
            hasSuspiciousSyntax: true,
            isInternal: true
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::IndirectReference, $status->reason);
    }

    public function test_detect_from_js_bundle_external_with_suspicious_syntax_returns_indirect_reference(): void
    {
        $status = $this->service->detectFromJsBundle(
            'https://external.com/api/${id}',
            hasSuspiciousSyntax: true,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::IndirectReference, $status->reason);
    }

    public function test_detect_from_js_bundle_localhost_returns_developer_leftover(): void
    {
        $status = $this->service->detectFromJsBundle(
            'http://localhost:3000/api',
            hasSuspiciousSyntax: false,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::DeveloperLeftover, $status->reason);
    }

    public function test_detect_from_js_bundle_127_0_0_1_returns_developer_leftover(): void
    {
        $status = $this->service->detectFromJsBundle(
            'http://127.0.0.1:8080/api',
            hasSuspiciousSyntax: false,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::DeveloperLeftover, $status->reason);
    }

    public function test_detect_from_js_bundle_ipv6_loopback_returns_developer_leftover(): void
    {
        $status = $this->service->detectFromJsBundle(
            'http://[::1]:8080/api',
            hasSuspiciousSyntax: false,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::DeveloperLeftover, $status->reason);
    }

    public function test_detect_from_js_bundle_external_url_returns_js_bundle_extracted(): void
    {
        $status = $this->service->detectFromJsBundle(
            'https://cdn.example.com/asset.js',
            hasSuspiciousSyntax: false,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::JsBundleExtracted, $status->reason);
    }

    public function test_detect_from_js_bundle_suspicious_takes_precedence_over_loopback(): void
    {
        // When both suspicious syntax and loopback are present, suspicious syntax wins
        $status = $this->service->detectFromJsBundle(
            'http://localhost:3000/api/${id}',
            hasSuspiciousSyntax: true,
            isInternal: false
        );

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::IndirectReference, $status->reason);
    }

    // ==================
    // shouldClearForSubdomain tests
    // ==================

    public function test_should_clear_for_subdomain_with_200_and_subdomain_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->shouldClearForSubdomain('https://api.example.com/health', 200);

        $this->assertTrue($result);
    }

    public function test_should_not_clear_for_subdomain_with_non_200_status(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->shouldClearForSubdomain('https://api.example.com/health', 404);

        $this->assertFalse($result);
    }

    public function test_should_not_clear_for_subdomain_with_main_domain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->shouldClearForSubdomain('https://example.com/page', 200);

        $this->assertFalse($result);
    }

    public function test_should_not_clear_for_subdomain_with_external_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $result = $this->service->shouldClearForSubdomain('https://other.com/page', 200);

        $this->assertFalse($result);
    }

    // ==================
    // Integration tests
    // ==================

    public function test_to_array_output_is_backward_compatible(): void
    {
        $status = $this->service->detectFromHttpResponse(403);
        $array = $status->toArray();

        $this->assertArrayHasKey('needsVerification', $array);
        $this->assertArrayHasKey('verificationReason', $array);
        $this->assertTrue($array['needsVerification']);
        $this->assertSame('bot_protection', $array['verificationReason']);
    }

    public function test_none_to_array_output_is_backward_compatible(): void
    {
        $status = $this->service->detectFromHttpResponse(200);
        $array = $status->toArray();

        $this->assertArrayHasKey('needsVerification', $array);
        $this->assertArrayHasKey('verificationReason', $array);
        $this->assertFalse($array['needsVerification']);
        $this->assertNull($array['verificationReason']);
    }
}

