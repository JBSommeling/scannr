<?php

namespace App\DTO;

use App\Enums\VerificationReason;

/**
 * Value object representing the verification status of a URL.
 *
 * Encapsulates whether a URL needs manual verification and the reasons why.
 * Supports multiple reasons (e.g., both js_bundle_extracted AND bot_protection).
 * Provides factory methods for common verification scenarios.
 */
readonly class VerificationStatus
{
    /**
     * @param bool $needsVerification Whether the URL needs manual verification.
     * @param array<VerificationReason> $reasons The reasons why verification is needed.
     */
    public function __construct(
        public bool $needsVerification,
        public array $reasons = [],
    ) {}

    /**
     * Create a status indicating no verification is needed.
     */
    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * Create a status for bot protection detection (403/405/Error/Timeout).
     */
    public static function forBotProtection(): self
    {
        return new self(true, [VerificationReason::BotProtection]);
    }

    /**
     * Create a status for indirect reference (template literals, dynamic syntax).
     */
    public static function forIndirectReference(): self
    {
        return new self(true, [VerificationReason::IndirectReference]);
    }

    /**
     * Create a status for developer leftover (localhost, 127.0.0.1).
     */
    public static function forDeveloperLeftover(): self
    {
        return new self(true, [VerificationReason::DeveloperLeftover]);
    }

    /**
     * Create a status for JS bundle extracted URLs.
     */
    public static function forJsBundleExtracted(): self
    {
        return new self(true, [VerificationReason::JsBundleExtracted]);
    }

    /**
     * Create a status from a reason enum value.
     */
    public static function fromReason(VerificationReason $reason): self
    {
        return new self(true, [$reason]);
    }

    /**
     * Create a status from multiple reasons.
     *
     * @param array<VerificationReason> $reasons
     */
    public static function fromReasons(array $reasons): self
    {
        if (empty($reasons)) {
            return self::none();
        }

        return new self(true, array_values(array_unique($reasons, SORT_REGULAR)));
    }

    /**
     * Create a status from array data (for hydration from queue/results).
     *
     * @param array{needsVerification?: bool, verificationReasons?: array<string>} $data
     */
    public static function fromArray(array $data): self
    {
        $needsVerification = $data['needsVerification'] ?? false;

        $reasons = [];
        if (isset($data['verificationReasons']) && is_array($data['verificationReasons'])) {
            foreach ($data['verificationReasons'] as $reasonValue) {
                $reason = VerificationReason::tryFrom($reasonValue);
                if ($reason !== null) {
                    $reasons[] = $reason;
                }
            }
        }

        if (empty($reasons) && !$needsVerification) {
            return self::none();
        }

        return new self($needsVerification, $reasons);
    }

    /**
     * Check if this status has a specific reason.
     */
    public function hasReason(VerificationReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /**
     * Get the primary reason (highest priority).
     *
     * Priority order: BotProtection > DeveloperLeftover > IndirectReference > JsBundleExtracted
     */
    public function getPrimaryReason(): ?VerificationReason
    {
        if (empty($this->reasons)) {
            return null;
        }

        $priority = [
            VerificationReason::BotProtection,
            VerificationReason::DeveloperLeftover,
            VerificationReason::IndirectReference,
            VerificationReason::JsBundleExtracted,
        ];

        foreach ($priority as $reason) {
            if (in_array($reason, $this->reasons, true)) {
                return $reason;
            }
        }

        return $this->reasons[0] ?? null;
    }

    /**
     * Merge with another status, combining all reasons.
     *
     * If either status needs verification, the result needs verification.
     * All unique reasons from both statuses are combined.
     */
    public function merge(self $other): self
    {
        if (!$this->needsVerification && !$other->needsVerification) {
            return self::none();
        }

        $combinedReasons = array_merge($this->reasons, $other->reasons);
        $uniqueReasons = array_values(array_unique($combinedReasons, SORT_REGULAR));

        return new self(true, $uniqueReasons);
    }

    /**
     * Add a reason to this status.
     */
    public function withReason(VerificationReason $reason): self
    {
        if (in_array($reason, $this->reasons, true)) {
            return $this;
        }

        return new self(true, [...$this->reasons, $reason]);
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{needsVerification: bool, verificationReasons: array<string>}
     */
    public function toArray(): array
    {
        $reasonValues = array_map(fn($r) => $r->value, $this->reasons);

        return [
            'needsVerification' => $this->needsVerification,
            'verificationReasons' => $reasonValues,
        ];
    }
}

