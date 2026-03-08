<?php

namespace Tests\Unit;

use App\Enums\Confidence;
use App\Enums\LinkFlag;
use App\Services\SeverityEvaluator;
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

    public function test_malformed_url_in_js_bundle_with_indirect_ref_still_high(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE, LinkFlag::MALFORMED_URL, LinkFlag::INDIRECT_REFERENCE],
            0,
            true
        );

        $this->assertEquals(Confidence::HIGH, $confidence);
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

    public function test_js_bundle_external_gets_low_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE],
            200,
            true
        );

        $this->assertEquals(Confidence::LOW, $confidence);
    }

    public function test_js_bundle_internal_gets_medium_confidence(): void
    {
        $confidence = $this->evaluator->evaluateConfidence(
            [LinkFlag::DETECTED_IN_JS_BUNDLE],
            200,
            false
        );

        $this->assertEquals(Confidence::MEDIUM, $confidence);
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
