<?php

namespace App\DTO;

use App\Enums\VerificationReason;

/**
 * Value object representing the verification status of a URL.
 *
 * Encapsulates whether a URL needs manual verification and the reason why.
 * Provides factory methods for common verification scenarios and supports
 * backward-compatible array serialization.
 */
readonly class VerificationStatus
{
    public function __construct(
        public bool $needsVerification,
        public ?VerificationReason $reason = null,
    ) {}

    /**
     * Create a status indicating no verification is needed.
     */
    public static function none(): self
    {
        return new self(false, null);
    }

    /**
     * Create a status for bot protection detection (403/405/Error/Timeout).
     */
    public static function forBotProtection(): self
    {
        return new self(true, VerificationReason::BotProtection);
    }

    /**
     * Create a status for indirect reference (template literals, dynamic syntax).
     */
    public static function forIndirectReference(): self
    {
        return new self(true, VerificationReason::IndirectReference);
    }

    /**
     * Create a status for developer leftover (localhost, 127.0.0.1).
     */
    public static function forDeveloperLeftover(): self
    {
        return new self(true, VerificationReason::DeveloperLeftover);
    }

    /**
     * Create a status for JS bundle extracted URLs.
     */
    public static function forJsBundleExtracted(): self
    {
        return new self(true, VerificationReason::JsBundleExtracted);
    }

    /**
     * Create a status from a reason enum value.
     */
    public static function fromReason(VerificationReason $reason): self
    {
        return new self(true, $reason);
    }

    /**
     * Create a status from array data (for hydration from queue/results).
     *
     * @param array{needsVerification?: bool, verificationReason?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        $needsVerification = $data['needsVerification'] ?? false;
        $reasonValue = $data['verificationReason'] ?? null;

        $reason = null;
        if ($reasonValue !== null) {
            $reason = VerificationReason::tryFrom($reasonValue);
        }

        return new self($needsVerification, $reason);
    }

    /**
     * Merge with another status, returning the more severe one.
     *
     * If either status needs verification, the result needs verification.
     * BotProtection always wins because it represents actual HTTP evidence,
     * while other reasons are speculative guesses from extraction time.
     * Otherwise, prefers the current reason if both have reasons.
     */
    public function merge(self $other): self
    {
        if (!$this->needsVerification && !$other->needsVerification) {
            return self::none();
        }

        // BotProtection always wins - it's based on actual HTTP response
        if ($other->reason === VerificationReason::BotProtection) {
            return $other;
        }

        if ($this->reason === VerificationReason::BotProtection) {
            return $this;
        }

        // Otherwise prefer the first with a reason
        if ($this->needsVerification && $this->reason !== null) {
            return $this;
        }

        if ($other->needsVerification && $other->reason !== null) {
            return $other;
        }

        return $this->needsVerification ? $this : $other;
    }

    /**
     * Convert to array for backward-compatible serialization.
     *
     * @return array{needsVerification: bool, verificationReason: string|null}
     */
    public function toArray(): array
    {
        return [
            'needsVerification' => $this->needsVerification,
            'verificationReason' => $this->reason?->value,
        ];
    }
}

