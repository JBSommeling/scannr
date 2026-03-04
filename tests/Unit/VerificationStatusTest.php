<?php

namespace Tests\Unit;

use App\DTO\VerificationStatus;
use App\Enums\VerificationReason;
use PHPUnit\Framework\TestCase;

class VerificationStatusTest extends TestCase
{
    // ==================
    // Factory method tests
    // ==================

    public function test_none_returns_no_verification_needed(): void
    {
        $status = VerificationStatus::none();

        $this->assertFalse($status->needsVerification);
        $this->assertEmpty($status->reasons);
    }

    public function test_for_bot_protection_returns_correct_status(): void
    {
        $status = VerificationStatus::forBotProtection();

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_for_indirect_reference_returns_correct_status(): void
    {
        $status = VerificationStatus::forIndirectReference();

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::IndirectReference));
    }

    public function test_for_developer_leftover_returns_correct_status(): void
    {
        $status = VerificationStatus::forDeveloperLeftover();

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::DeveloperLeftover));
    }

    public function test_for_js_bundle_extracted_returns_correct_status(): void
    {
        $status = VerificationStatus::forJsBundleExtracted();

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::JsBundleExtracted));
    }

    public function test_from_reason_creates_status_with_given_reason(): void
    {
        $status = VerificationStatus::fromReason(VerificationReason::BotProtection);

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_from_reasons_creates_status_with_multiple_reasons(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::BotProtection,
        ]);

        $this->assertTrue($status->needsVerification);
        $this->assertCount(2, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_from_reasons_with_empty_array_returns_none(): void
    {
        $status = VerificationStatus::fromReasons([]);

        $this->assertFalse($status->needsVerification);
        $this->assertEmpty($status->reasons);
    }

    public function test_from_reasons_deduplicates_reasons(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::BotProtection,
            VerificationReason::BotProtection,
            VerificationReason::IndirectReference,
        ]);

        $this->assertCount(2, $status->reasons);
    }

    // ==================
    // fromArray tests
    // ==================

    public function test_from_array_with_verification_needed(): void
    {
        $data = [
            'needsVerification' => true,
            'verificationReasons' => ['bot_protection'],
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertTrue($status->needsVerification);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_from_array_with_multiple_reasons(): void
    {
        $data = [
            'needsVerification' => true,
            'verificationReasons' => ['js_bundle_extracted', 'bot_protection'],
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertTrue($status->needsVerification);
        $this->assertCount(2, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_from_array_with_no_verification(): void
    {
        $data = [
            'needsVerification' => false,
            'verificationReasons' => [],
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertFalse($status->needsVerification);
        $this->assertEmpty($status->reasons);
    }

    public function test_from_array_with_empty_array_defaults_to_no_verification(): void
    {
        $status = VerificationStatus::fromArray([]);

        $this->assertFalse($status->needsVerification);
        $this->assertEmpty($status->reasons);
    }

    public function test_from_array_with_invalid_reason_ignores_invalid(): void
    {
        $data = [
            'needsVerification' => true,
            'verificationReasons' => ['invalid_reason', 'bot_protection'],
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_from_array_with_all_verification_reasons(): void
    {
        $reasons = [
            'bot_protection',
            'indirect_reference',
            'developer_leftover',
            'js_bundle_extracted',
        ];

        $status = VerificationStatus::fromArray([
            'needsVerification' => true,
            'verificationReasons' => $reasons,
        ]);

        $this->assertCount(4, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
        $this->assertTrue($status->hasReason(VerificationReason::IndirectReference));
        $this->assertTrue($status->hasReason(VerificationReason::DeveloperLeftover));
        $this->assertTrue($status->hasReason(VerificationReason::JsBundleExtracted));
    }

    // ==================
    // toArray tests
    // ==================

    public function test_to_array_serializes_single_reason(): void
    {
        $status = VerificationStatus::forBotProtection();

        $array = $status->toArray();

        $this->assertSame([
            'needsVerification' => true,
            'verificationReasons' => ['bot_protection'],
        ], $array);
    }

    public function test_to_array_serializes_multiple_reasons(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::BotProtection,
        ]);

        $array = $status->toArray();

        $this->assertSame([
            'needsVerification' => true,
            'verificationReasons' => ['js_bundle_extracted', 'bot_protection'],
        ], $array);
    }

    public function test_to_array_serializes_empty_reasons(): void
    {
        $status = VerificationStatus::none();

        $array = $status->toArray();

        $this->assertSame([
            'needsVerification' => false,
            'verificationReasons' => [],
        ], $array);
    }

    public function test_to_array_roundtrip_with_from_array(): void
    {
        $original = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::IndirectReference,
        ]);

        $serialized = $original->toArray();
        $hydrated = VerificationStatus::fromArray($serialized);

        $this->assertSame($original->needsVerification, $hydrated->needsVerification);
        $this->assertEquals($original->reasons, $hydrated->reasons);
    }

    // ==================
    // hasReason tests
    // ==================

    public function test_has_reason_returns_true_when_present(): void
    {
        $status = VerificationStatus::forBotProtection();

        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }

    public function test_has_reason_returns_false_when_not_present(): void
    {
        $status = VerificationStatus::forBotProtection();

        $this->assertFalse($status->hasReason(VerificationReason::IndirectReference));
    }

    public function test_has_reason_works_with_multiple_reasons(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::BotProtection,
        ]);

        $this->assertTrue($status->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
        $this->assertFalse($status->hasReason(VerificationReason::IndirectReference));
    }

    // ==================
    // getPrimaryReason tests
    // ==================

    public function test_get_primary_reason_returns_null_when_no_reasons(): void
    {
        $status = VerificationStatus::none();

        $this->assertNull($status->getPrimaryReason());
    }

    public function test_get_primary_reason_returns_single_reason(): void
    {
        $status = VerificationStatus::forIndirectReference();

        $this->assertSame(VerificationReason::IndirectReference, $status->getPrimaryReason());
    }

    public function test_get_primary_reason_respects_priority_order(): void
    {
        // BotProtection has highest priority
        $status = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::IndirectReference,
            VerificationReason::BotProtection,
        ]);

        $this->assertSame(VerificationReason::BotProtection, $status->getPrimaryReason());
    }

    public function test_get_primary_reason_developer_leftover_over_indirect_reference(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::IndirectReference,
            VerificationReason::DeveloperLeftover,
        ]);

        $this->assertSame(VerificationReason::DeveloperLeftover, $status->getPrimaryReason());
    }

    public function test_get_primary_reason_indirect_reference_over_js_bundle(): void
    {
        $status = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::IndirectReference,
        ]);

        $this->assertSame(VerificationReason::IndirectReference, $status->getPrimaryReason());
    }

    // ==================
    // withReason tests
    // ==================

    public function test_with_reason_adds_new_reason(): void
    {
        $status = VerificationStatus::forJsBundleExtracted();

        $newStatus = $status->withReason(VerificationReason::BotProtection);

        $this->assertCount(2, $newStatus->reasons);
        $this->assertTrue($newStatus->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($newStatus->hasReason(VerificationReason::BotProtection));
    }

    public function test_with_reason_returns_same_when_already_present(): void
    {
        $status = VerificationStatus::forBotProtection();

        $newStatus = $status->withReason(VerificationReason::BotProtection);

        $this->assertSame($status, $newStatus);
    }

    public function test_with_reason_sets_needs_verification(): void
    {
        $status = VerificationStatus::none();

        $newStatus = $status->withReason(VerificationReason::BotProtection);

        $this->assertTrue($newStatus->needsVerification);
    }

    // ==================
    // merge tests
    // ==================

    public function test_merge_two_none_returns_none(): void
    {
        $a = VerificationStatus::none();
        $b = VerificationStatus::none();

        $merged = $a->merge($b);

        $this->assertFalse($merged->needsVerification);
        $this->assertEmpty($merged->reasons);
    }

    public function test_merge_none_with_verification_returns_verification(): void
    {
        $none = VerificationStatus::none();
        $verification = VerificationStatus::forBotProtection();

        $merged = $none->merge($verification);

        $this->assertTrue($merged->needsVerification);
        $this->assertTrue($merged->hasReason(VerificationReason::BotProtection));
    }

    public function test_merge_verification_with_none_returns_verification(): void
    {
        $verification = VerificationStatus::forBotProtection();
        $none = VerificationStatus::none();

        $merged = $verification->merge($none);

        $this->assertTrue($merged->needsVerification);
        $this->assertTrue($merged->hasReason(VerificationReason::BotProtection));
    }

    public function test_merge_combines_all_reasons(): void
    {
        $first = VerificationStatus::forJsBundleExtracted();
        $second = VerificationStatus::forBotProtection();

        $merged = $first->merge($second);

        $this->assertTrue($merged->needsVerification);
        $this->assertCount(2, $merged->reasons);
        $this->assertTrue($merged->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($merged->hasReason(VerificationReason::BotProtection));
    }

    public function test_merge_deduplicates_reasons(): void
    {
        $first = VerificationStatus::forBotProtection();
        $second = VerificationStatus::forBotProtection();

        $merged = $first->merge($second);

        $this->assertCount(1, $merged->reasons);
    }

    public function test_merge_multiple_reasons_from_both(): void
    {
        $first = VerificationStatus::fromReasons([
            VerificationReason::JsBundleExtracted,
            VerificationReason::IndirectReference,
        ]);
        $second = VerificationStatus::fromReasons([
            VerificationReason::BotProtection,
            VerificationReason::IndirectReference, // duplicate
        ]);

        $merged = $first->merge($second);

        $this->assertCount(3, $merged->reasons);
        $this->assertTrue($merged->hasReason(VerificationReason::JsBundleExtracted));
        $this->assertTrue($merged->hasReason(VerificationReason::IndirectReference));
        $this->assertTrue($merged->hasReason(VerificationReason::BotProtection));
    }

    public function test_merge_primary_reason_is_bot_protection_when_present(): void
    {
        $jsBundleExtracted = VerificationStatus::forJsBundleExtracted();
        $botProtection = VerificationStatus::forBotProtection();

        $merged = $jsBundleExtracted->merge($botProtection);

        $this->assertSame(VerificationReason::BotProtection, $merged->getPrimaryReason());
    }

    // ==================
    // readonly tests
    // ==================

    public function test_verification_status_is_readonly(): void
    {
        $reflection = new \ReflectionClass(VerificationStatus::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_properties_are_accessible(): void
    {
        $status = new VerificationStatus(true, [VerificationReason::BotProtection]);

        $this->assertTrue($status->needsVerification);
        $this->assertCount(1, $status->reasons);
        $this->assertTrue($status->hasReason(VerificationReason::BotProtection));
    }
}

