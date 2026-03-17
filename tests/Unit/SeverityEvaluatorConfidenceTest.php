<?php

namespace Tests\Unit;

use Scannr\Enums\Confidence;
use Scannr\Enums\LinkFlag;
use Scannr\Services\SeverityEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeverityEvaluatorConfidenceTest extends TestCase
{
    private SeverityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SeverityEvaluator;
    }

    public function test_developer_leftover_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DEVELOPER_LEFTOVER],
            200,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_developer_leftover_in_js_bundle_still_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::DEVELOPER_LEFTOVER],
            200,
            true // external (like http://localhost classified as external)
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_malformed_url_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::MALFORMED_URL],
            0,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_malformed_url_in_js_bundle_gets_low_confidence(): void
    {
        // Malformed URL from JS bundle without indirect_reference (e.g. backtick/newline only)
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::MALFORMED_URL],
            0,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_malformed_and_indirect_from_js_bundle_gets_low(): void
    {
        // Template literal like ${var} — fires both flags from JS bundle
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::MALFORMED_URL, LinkFlag::INDIRECT_REFERENCE],
            0,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_indirect_reference_without_malformed_gets_low(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::INDIRECT_REFERENCE],
            200,
            false
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_bot_protection_gets_low_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::EXTERNAL_PLATFORM, LinkFlag::BOT_PROTECTION],
            405,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    #[DataProvider('botProtectionStatusProvider')]
    public function test_bot_protection_stays_low_regardless_of_status(int $status): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::EXTERNAL_PLATFORM, LinkFlag::BOT_PROTECTION, LinkFlag::STATUS_4XX],
            $status,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public static function botProtectionStatusProvider(): array
    {
        return [
            '403 Forbidden' => [403],
            '405 Method Not Allowed' => [405],
            '406 Not Acceptable' => [406],
            '429 Too Many Requests' => [429],
        ];
    }

    public function test_bot_protection_from_js_bundle_stays_low(): void
    {
        // Even with JS bundle + verified status, bot_protection takes priority
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::EXTERNAL_PLATFORM, LinkFlag::BOT_PROTECTION, LinkFlag::STATUS_4XX],
            403,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_js_bundle_external_without_verified_status_gets_low_confidence(): void
    {
        // JS bundle external link with error/non-numeric status → LOW
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE],
            'error',
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_js_bundle_external_with_4xx_gets_high_confidence(): void
    {
        // A JS bundle link that returns a clear 404 is confirmed broken
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::EXTERNAL_PLATFORM, LinkFlag::STATUS_4XX],
            404,
            true
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_js_bundle_external_with_200_gets_high_confidence(): void
    {
        // A JS bundle link that returns 200 is confirmed working
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::EXTERNAL_PLATFORM],
            200,
            true
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_js_bundle_internal_without_verified_status_gets_medium_confidence(): void
    {
        // JS bundle internal link with ambiguous status → MEDIUM
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE],
            'error',
            false
        );

        $this->assertEquals(Confidence::MEDIUM, $confidence);
    }

    public function test_js_bundle_internal_with_200_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE],
            200,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_status_5xx_gets_medium_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::STATUS_5XX],
            500,
            false
        );

        $this->assertEquals(Confidence::MEDIUM, $confidence);
    }

    public function test_redirect_chain_gets_medium_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::REDIRECT_CHAIN],
            200,
            false
        );

        $this->assertEquals(Confidence::MEDIUM, $confidence);
    }

    public function test_timeout_gets_medium_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::TIMEOUT],
            'timeout',
            false
        );

        $this->assertEquals(Confidence::MEDIUM, $confidence);
    }

    public function test_clean_result_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::STATIC_HTML],
            200,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_internal_404_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::STATUS_4XX],
            404,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }

    public function test_http_on_https_gets_high_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::HTTP_ON_HTTPS],
            200,
            false
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
    }
}
