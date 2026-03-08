<?php

namespace App\DTO;

/**
 * Value object representing the integrity score of a scanned site.
 *
 * Holds the overall score (0–100), grade, category breakdown,
 * individual penalties applied, and a summary of issue counts.
 */
readonly class IntegrityScoreResult
{
    /**
     * @param  float  $overallScore  The overall integrity score (0–100).
     * @param  string  $grade  Human-readable grade: Excellent, Good, Needs Attention, Critical.
     * @param  string  $gradeColor  Color indicator: green, yellow, orange, red.
     * @param  string  $gradeEmoji  Emoji indicator: 🟢, 🟡, 🟠, 🔴.
     * @param  array<string, float>  $categoryScores  Sub-scores keyed by category.
     * @param  array<array{flag: string, basePenalty: float, confidence: string, multiplier: float, dampening: float, effectivePenalty: float, url: string}>  $penalties  Individual penalty details.
     * @param  array{criticalIssues: int, warnings: int, brokenLinks: int, manualVerification: int}  $summary  Issue count summary.
     */
    public function __construct(
        public float $overallScore,
        public string $grade,
        public string $gradeColor,
        public string $gradeEmoji,
        public array $categoryScores,
        public array $penalties,
        public array $summary,
    ) {}

    /**
     * Create from calculated values with automatic grade derivation.
     *
     * @param  float  $overallScore
     * @param  array<string, float>  $categoryScores
     * @param  array  $penalties
     * @param  array{criticalIssues: int, warnings: int, brokenLinks: int, manualVerification: int}  $summary
     * @param  array  $gradeThresholds  Grade threshold config.
     */
    public static function create(
        float $overallScore,
        array $categoryScores,
        array $penalties,
        array $summary,
        array $gradeThresholds = [],
    ): self {
        $score = max(0, min(100, $overallScore));
        $gradeInfo = self::resolveGrade($score, $gradeThresholds);

        return new self(
            overallScore: round($score, 1),
            grade: $gradeInfo['grade'],
            gradeColor: $gradeInfo['color'],
            gradeEmoji: $gradeInfo['emoji'],
            categoryScores: array_map(fn (float $s) => round(max(0, min(100, $s)), 1), $categoryScores),
            penalties: $penalties,
            summary: $summary,
        );
    }

    /**
     * Create a perfect score (no issues found).
     */
    public static function perfect(): self
    {
        return self::create(100, [
            'link_integrity' => 100,
            'security_hygiene' => 100,
            'technical_hygiene' => 100,
            'redirect_health' => 100,
            'link_verifiability' => 100,
        ], [], [
            'criticalIssues' => 0,
            'warnings' => 0,
            'brokenLinks' => 0,
            'manualVerification' => 0,
        ]);
    }

    /**
     * Resolve grade, color, and emoji from score.
     *
     * @return array{grade: string, color: string, emoji: string}
     */
    protected static function resolveGrade(float $score, array $thresholds = []): array
    {
        $excellent = $thresholds['excellent'] ?? 90;
        $good = $thresholds['good'] ?? 75;
        $needsAttention = $thresholds['needs_attention'] ?? 50;

        if ($score >= $excellent) {
            return ['grade' => 'Excellent', 'color' => 'green', 'emoji' => '🟢'];
        }

        if ($score >= $good) {
            return ['grade' => 'Good', 'color' => 'yellow', 'emoji' => '🟡'];
        }

        if ($score >= $needsAttention) {
            return ['grade' => 'Needs Attention', 'color' => 'orange', 'emoji' => '🟠'];
        }

        return ['grade' => 'Critical', 'color' => 'red', 'emoji' => '🔴'];
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{
     *     overallScore: float,
     *     grade: string,
     *     gradeColor: string,
     *     gradeEmoji: string,
     *     categoryScores: array<string, float>,
     *     penalties: array,
     *     summary: array{criticalIssues: int, warnings: int, brokenLinks: int, manualVerification: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'overallScore' => $this->overallScore,
            'grade' => $this->grade,
            'gradeColor' => $this->gradeColor,
            'gradeEmoji' => $this->gradeEmoji,
            'categoryScores' => $this->categoryScores,
            'penalties' => $this->penalties,
            'summary' => $this->summary,
        ];
    }
}
