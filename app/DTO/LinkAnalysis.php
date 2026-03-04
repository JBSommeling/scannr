<?php

namespace App\DTO;

use App\Enums\Confidence;
use App\Enums\LinkFlag;
use App\Enums\Severity;

/**
 * Value object representing the analysis of a link.
 *
 * Encapsulates flags (observations), severity (derived), confidence level,
 * and verification recommendation. Replaces the old VerificationStatus.
 */
readonly class LinkAnalysis
{
    /**
     * @param array<LinkFlag> $flags The observation flags for this link.
     * @param Severity $severity The derived severity level.
     * @param Confidence $confidence The confidence level in the analysis.
     * @param string $verification Verification recommendation: 'recommended', 'optional', or 'none'.
     */
    public function __construct(
        public array $flags = [],
        public Severity $severity = Severity::INFO,
        public Confidence $confidence = Confidence::HIGH,
        public string $verification = 'none',
    ) {}

    /**
     * Create a new analysis with the given parameters.
     *
     * @param array<LinkFlag> $flags
     */
    public static function create(
        array $flags,
        Severity $severity,
        Confidence $confidence,
        string $verification,
    ): self {
        return new self($flags, $severity, $confidence, $verification);
    }

    /**
     * Create an empty analysis (no flags, no issues).
     */
    public static function none(): self
    {
        return new self([], Severity::INFO, Confidence::HIGH, 'none');
    }

    /**
     * Create analysis from array data (for hydration from queue/results).
     *
     * @param array{flags?: array<string>, confidence?: string, verification?: string} $data
     */
    public static function fromArray(array $data): self
    {
        $flags = [];
        if (isset($data['flags']) && is_array($data['flags'])) {
            foreach ($data['flags'] as $flagValue) {
                $flag = LinkFlag::tryFrom($flagValue);
                if ($flag !== null) {
                    $flags[] = $flag;
                }
            }
        }

        $severity = Severity::INFO;
        if (isset($data['severity'])) {
            $severity = Severity::tryFrom($data['severity']) ?? Severity::INFO;
        }

        $confidence = Confidence::HIGH;
        if (isset($data['confidence'])) {
            $confidence = Confidence::tryFrom($data['confidence']) ?? Confidence::HIGH;
        }

        $verification = $data['verification'] ?? 'none';
        if (!in_array($verification, ['recommended', 'optional', 'none'], true)) {
            $verification = 'none';
        }

        return new self($flags, $severity, $confidence, $verification);
    }

    /**
     * Check if this analysis has a specific flag.
     */
    public function hasFlag(LinkFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Check if this analysis has any of the given flags.
     *
     * @param array<LinkFlag> $flags
     */
    public function hasAnyFlag(array $flags): bool
    {
        foreach ($flags as $flag) {
            if ($this->hasFlag($flag)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get flag values as strings.
     *
     * @return array<string>
     */
    public function getFlagValues(): array
    {
        return array_map(fn(LinkFlag $f) => $f->value, $this->flags);
    }

    /**
     * Add a flag to the analysis (returns new instance).
     */
    public function withFlag(LinkFlag $flag): self
    {
        if ($this->hasFlag($flag)) {
            return $this;
        }

        return new self(
            [...$this->flags, $flag],
            $this->severity,
            $this->confidence,
            $this->verification,
        );
    }

    /**
     * Merge with another analysis, combining flags.
     */
    public function merge(self $other): self
    {
        $combinedFlags = array_merge($this->flags, $other->flags);
        $uniqueFlags = array_values(array_unique($combinedFlags, SORT_REGULAR));

        // Take the more severe severity
        $severity = $this->severity;
        if ($other->severity === Severity::CRITICAL ||
            ($other->severity === Severity::WARNING && $this->severity === Severity::INFO)) {
            $severity = $other->severity;
        }

        // Take the lower confidence
        $confidence = $this->confidence;
        if ($other->confidence === Confidence::LOW ||
            ($other->confidence === Confidence::MEDIUM && $this->confidence === Confidence::HIGH)) {
            $confidence = $other->confidence;
        }

        // Take the more urgent verification
        $verification = $this->verification;
        if ($other->verification === 'recommended' ||
            ($other->verification === 'optional' && $this->verification === 'none')) {
            $verification = $other->verification;
        }

        return new self($uniqueFlags, $severity, $confidence, $verification);
    }

    /**
     * Convert to array for serialization (matches target JSON structure).
     *
     * @return array{flags: array<string>, severity: string, confidence: string, verification: string}
     */
    public function toArray(): array
    {
        return [
            'flags' => $this->getFlagValues(),
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'verification' => $this->verification,
        ];
    }
}

