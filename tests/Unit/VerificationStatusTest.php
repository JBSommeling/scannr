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
        $this->assertNull($status->reason);
    }

    public function test_for_bot_protection_returns_correct_status(): void
    {
        $status = VerificationStatus::forBotProtection();

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_for_indirect_reference_returns_correct_status(): void
    {
        $status = VerificationStatus::forIndirectReference();

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::IndirectReference, $status->reason);
    }

    public function test_for_developer_leftover_returns_correct_status(): void
    {
        $status = VerificationStatus::forDeveloperLeftover();

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::DeveloperLeftover, $status->reason);
    }

    public function test_for_js_bundle_extracted_returns_correct_status(): void
    {
        $status = VerificationStatus::forJsBundleExtracted();

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::JsBundleExtracted, $status->reason);
    }

    public function test_from_reason_creates_status_with_given_reason(): void
    {
        $status = VerificationStatus::fromReason(VerificationReason::BotProtection);

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    // ==================
    // fromArray tests
    // ==================

    public function test_from_array_with_verification_needed(): void
    {
        $data = [
            'needsVerification' => true,
            'verificationReason' => 'bot_protection',
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }

    public function test_from_array_with_no_verification(): void
    {
        $data = [
            'needsVerification' => false,
            'verificationReason' => null,
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_from_array_with_empty_array_defaults_to_no_verification(): void
    {
        $status = VerificationStatus::fromArray([]);

        $this->assertFalse($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_from_array_with_invalid_reason_returns_null_reason(): void
    {
        $data = [
            'needsVerification' => true,
            'verificationReason' => 'invalid_reason',
        ];

        $status = VerificationStatus::fromArray($data);

        $this->assertTrue($status->needsVerification);
        $this->assertNull($status->reason);
    }

    public function test_from_array_with_all_verification_reasons(): void
    {
        $reasons = [
            'bot_protection' => VerificationReason::BotProtection,
            'indirect_reference' => VerificationReason::IndirectReference,
            'developer_leftover' => VerificationReason::DeveloperLeftover,
            'js_bundle_extracted' => VerificationReason::JsBundleExtracted,
        ];

        foreach ($reasons as $stringValue => $enum) {
            $status = VerificationStatus::fromArray([
                'needsVerification' => true,
                'verificationReason' => $stringValue,
            ]);

            $this->assertSame($enum, $status->reason, "Failed for reason: {$stringValue}");
        }
    }

    // ==================
    // toArray tests
    // ==================

    public function test_to_array_serializes_with_string_reason(): void
    {
        $status = VerificationStatus::forBotProtection();

        $array = $status->toArray();

        $this->assertSame([
            'needsVerification' => true,
            'verificationReason' => 'bot_protection',
        ], $array);
    }

    public function test_to_array_serializes_null_reason(): void
    {
        $status = VerificationStatus::none();

        $array = $status->toArray();

        $this->assertSame([
            'needsVerification' => false,
            'verificationReason' => null,
        ], $array);
    }

    public function test_to_array_roundtrip_with_from_array(): void
    {
        $original = VerificationStatus::forIndirectReference();

        $serialized = $original->toArray();
        $hydrated = VerificationStatus::fromArray($serialized);

        $this->assertSame($original->needsVerification, $hydrated->needsVerification);
        $this->assertSame($original->reason, $hydrated->reason);
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
        $this->assertNull($merged->reason);
    }

    public function test_merge_none_with_verification_returns_verification(): void
    {
        $none = VerificationStatus::none();
        $verification = VerificationStatus::forBotProtection();

        $merged = $none->merge($verification);

        $this->assertTrue($merged->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $merged->reason);
    }

    public function test_merge_verification_with_none_returns_verification(): void
    {
        $verification = VerificationStatus::forBotProtection();
        $none = VerificationStatus::none();

        $merged = $verification->merge($none);

        $this->assertTrue($merged->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $merged->reason);
    }

    public function test_merge_two_verifications_returns_first_with_reason(): void
    {
        $first = VerificationStatus::forBotProtection();
        $second = VerificationStatus::forIndirectReference();

        $merged = $first->merge($second);

        $this->assertTrue($merged->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $merged->reason);
    }

    public function test_merge_verification_without_reason_prefers_other_with_reason(): void
    {
        $withoutReason = new VerificationStatus(true, null);
        $withReason = VerificationStatus::forIndirectReference();

        $merged = $withoutReason->merge($withReason);

        $this->assertTrue($merged->needsVerification);
        $this->assertSame(VerificationReason::IndirectReference, $merged->reason);
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
        $status = new VerificationStatus(true, VerificationReason::BotProtection);

        $this->assertTrue($status->needsVerification);
        $this->assertSame(VerificationReason::BotProtection, $status->reason);
    }
}

