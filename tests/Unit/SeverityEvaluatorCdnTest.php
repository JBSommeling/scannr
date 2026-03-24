<?php

namespace Tests\Unit;

use Scannr\Enums\LinkFlag;
use Scannr\Enums\Severity;
use Scannr\Services\SeverityEvaluator;
use PHPUnit\Framework\TestCase;

class SeverityEvaluatorCdnTest extends TestCase
{
    private SeverityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SeverityEvaluator;
    }

    public function test_cdn_asset_with_4xx_is_warning_not_critical(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::CDN_ASSET, LinkFlag::STATUS_4XX],
            404
        );

        $this->assertEquals(Severity::WARNING, $severity);
    }

    public function test_internal_4xx_without_cdn_is_critical(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::STATUS_4XX],
            404
        );

        $this->assertEquals(Severity::CRITICAL, $severity);
    }

    public function test_cdn_asset_with_bot_protection_is_warning(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::CDN_ASSET, LinkFlag::BOT_PROTECTION, LinkFlag::STATUS_4XX],
            403
        );

        $this->assertEquals(Severity::WARNING, $severity);
    }

    public function test_cdn_asset_with_5xx_is_still_critical(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::CDN_ASSET, LinkFlag::STATUS_5XX],
            500
        );

        $this->assertEquals(Severity::CRITICAL, $severity);
    }

    public function test_cdn_asset_without_error_is_info(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::CDN_ASSET],
            200
        );

        $this->assertEquals(Severity::INFO, $severity);
    }

    public function test_cdn_asset_with_timeout_is_warning(): void
    {
        $severity = $this->evaluator->evaluate(
            [LinkFlag::CDN_ASSET, LinkFlag::TIMEOUT],
            'Timeout'
        );

        $this->assertEquals(Severity::WARNING, $severity);
    }
}
