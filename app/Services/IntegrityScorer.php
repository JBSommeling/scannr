<?php

namespace App\Services;

use App\DTO\IntegrityScoreResult;
use App\Enums\IssueType;
use App\Enums\LinkFlag;

/**
 * Weight-based integrity scoring engine.
 *
 * Calculates a site integrity score (0–100) by subtracting weighted
 * penalties from a base score of 100. Each link issue is penalized
 * based on its type, confidence level, and how many times that issue
 * type has already occurred (duplicate dampening).
 */
class IntegrityScorer
{
    public function __construct(
        protected ScanStatistics $scanStatistics,
    ) {}

    /**
     * Calculate the integrity score for a set of scan results.
     *
     * @param  array  $results  Array of scan result items (each with 'analysis', 'type', 'status', 'url' keys).
     * @return IntegrityScoreResult
     */
    public function calculate(array $results): IntegrityScoreResult
    {
        if (empty($results)) {
            return IntegrityScoreResult::perfect();
        }

        $config = $this->getConfig();
        $penaltyWeights = $config['penalties'];
        $confidenceMultipliers = $config['confidence_multipliers'];
        $dampening = $config['dampening'];
        $categories = $config['categories'];
        $gradeThresholds = $config['grades'];

        // Track occurrence counts per issue type for dampening
        $occurrenceCounts = [];

        // Extract category type mappings from config and initialise penalty accumulators
        $categoryTypes = [];
        $categoryPenalties = [];
        foreach ($categories as $catName => $catConfig) {
            $categoryTypes[$catName] = $catConfig['types'] ?? [];
            $categoryPenalties[$catName] = 0;
        }

        $appliedPenalties = [];
        $totalPenalty = 0;

        // Summary counters
        $criticalIssues = 0;
        $warnings = 0;
        $manualVerification = 0;
        $brokenLinks = 0;

        foreach ($results as $result) {
            $analysis = $result['analysis'] ?? [];
            $flags = $analysis['flags'] ?? [];
            $severity = $analysis['severity'] ?? 'info';
            $confidence = $analysis['confidence'] ?? 'high';
            $verification = $analysis['verification'] ?? 'none';
            $type = $result['type'] ?? 'internal';
            $status = $result['status'] ?? '';
            $url = $result['url'] ?? '';

            // Count summary items
            if ($severity === 'critical') {
                $criticalIssues++;
            } elseif ($severity === 'warning') {
                $warnings++;
            }
            if ($verification === 'recommended') {
                $manualVerification++;
            }

            // Count broken links
            if ($this->scanStatistics->isBrokenResult($result)) {
                $brokenLinks++;
            }

            // Determine the penalizing issue type for this result
            $issueType = $this->resolveIssueType($flags, $type, $status);

            if ($issueType === null) {
                continue;
            }

            $basePenalty = $penaltyWeights[$issueType->value] ?? 0;
            if ($basePenalty <= 0) {
                continue;
            }

            // Confidence multiplier
            $multiplier = $confidenceMultipliers[$confidence] ?? 1.0;

            // Duplicate dampening
            $occurrenceCounts[$issueType->value] = ($occurrenceCounts[$issueType->value] ?? 0) + 1;
            $count = $occurrenceCounts[$issueType->value];
            $dampeningFactor = $this->calculateDampening($count, $dampening);

            $effectivePenalty = $basePenalty * $multiplier * $dampeningFactor;

            $totalPenalty += $effectivePenalty;

            // Assign to category
            $category = $this->resolveCategory($issueType->value, $categoryTypes);
            if ($category !== null) {
                $categoryPenalties[$category] += $effectivePenalty;
            }

            $appliedPenalties[] = [
                'issueType' => $issueType->value,
                'basePenalty' => $basePenalty,
                'confidence' => $confidence,
                'multiplier' => $multiplier,
                'dampening' => $dampeningFactor,
                'effectivePenalty' => round($effectivePenalty, 2),
                'url' => $url,
            ];
        }

        $categoryScores = [];
        $categoryMultiplier = $config['category_penalty_multiplier'];
        foreach ($categoryPenalties as $cat => $penalty) {
            $categoryScores[$cat] = max(0, 100 - ($penalty * $categoryMultiplier));
        }

        // Overall score = direct subtraction of all penalties from 100
        $overallScore = 100 - $totalPenalty;

        return IntegrityScoreResult::create(
            overallScore: $overallScore,
            categoryScores: $categoryScores,
            penalties: $appliedPenalties,
            summary: [
                'criticalIssues' => $criticalIssues,
                'warnings' => $warnings,
                'brokenLinks' => $brokenLinks,
                'manualVerification' => $manualVerification,
            ],
            gradeThresholds: $gradeThresholds,
        );
    }

    /**
     * Resolve the primary penalizing issue type from a result's flags.
     *
     * Returns the most significant penalty key, or null if no penalty applies.
     *
     * @param  array<string>  $flags
     * @param  string  $type  'internal' or 'external'
     * @param  string|int  $status  HTTP status code
     */
    protected function resolveIssueType(array $flags, string $type, string|int $status): ?IssueType
    {
        $hasFormEndpoint = in_array(LinkFlag::FORM_ENDPOINT->value, $flags, true);
        $hasStatus4xx = in_array(LinkFlag::STATUS_4XX->value, $flags, true);
        $hasBotProtection = in_array(LinkFlag::BOT_PROTECTION->value, $flags, true);
        $hasExternalPlatform = in_array(LinkFlag::EXTERNAL_PLATFORM->value, $flags, true);

        // Skip healthy form endpoints (non-404 4xx)
        if ($hasFormEndpoint && $hasStatus4xx) {
            $statusInt = is_numeric($status) ? (int) $status : 0;
            $healthyFormStatuses = [400, 401, 403, 405, 422, 429];
            if (in_array($statusInt, $healthyFormStatuses, true)) {
                return null;
            }
            return IssueType::FORM_ENDPOINT_404;
        }

        // Priority order: most severe first
        if (in_array(LinkFlag::DEVELOPER_LEFTOVER->value, $flags, true)) {
            return IssueType::DEVELOPER_LEFTOVER;
        }

        if (in_array(LinkFlag::CONNECTION_ERROR->value, $flags, true) && ! $hasExternalPlatform) {
            return IssueType::CONNECTION_ERROR;
        }

        if (in_array(LinkFlag::STATUS_5XX->value, $flags, true)) {
            return IssueType::STATUS_5XX;
        }

        // Internal 4xx without bot protection
        if ($hasStatus4xx && $type === 'internal' && ! $hasBotProtection) {
            return IssueType::STATUS_4XX_INTERNAL;
        }

        if (in_array(LinkFlag::MALFORMED_URL->value, $flags, true)) {
            return IssueType::MALFORMED_URL;
        }

        if (in_array(LinkFlag::EXCESSIVE_REDIRECTS->value, $flags, true)) {
            return IssueType::EXCESSIVE_REDIRECTS;
        }

        if (in_array(LinkFlag::HTTP_ON_HTTPS->value, $flags, true)) {
            return IssueType::HTTP_ON_HTTPS;
        }

        if (in_array(LinkFlag::TIMEOUT->value, $flags, true)) {
            return IssueType::TIMEOUT;
        }

        if (in_array(LinkFlag::REDIRECT_CHAIN->value, $flags, true)) {
            return IssueType::REDIRECT_CHAIN;
        }

        if ($hasBotProtection) {
            return IssueType::BOT_PROTECTION;
        }

        if (in_array(LinkFlag::RATE_LIMITED->value, $flags, true)) {
            return IssueType::RATE_LIMITED;
        }

        // External 4xx with platform flag but no bot protection — likely a genuinely broken external link
        if ($hasStatus4xx && $hasExternalPlatform) {
            return IssueType::STATUS_4XX_EXTERNAL_PLATFORM;
        }

        return null;
    }

    /**
     * Calculate the dampening factor based on occurrence count.
     *
     * @param  int  $count  How many times this issue type has occurred.
     * @param  array  $dampening  Dampening config.
     * @return float  Factor to multiply penalty by (0–1).
     */
    protected function calculateDampening(int $count, array $dampening): float
    {
        $tier1Max = $dampening['tier_1_max'] ?? 1;
        $tier2Max = $dampening['tier_2_max'] ?? 5;
        $tier2Factor = $dampening['tier_2_factor'] ?? 0.5;
        $tier3Factor = $dampening['tier_3_factor'] ?? 0.25;

        if ($count <= $tier1Max) {
            return 1.0;
        }

        if ($count <= $tier2Max) {
            return $tier2Factor;
        }

        return $tier3Factor;
    }

    /**
     * Resolve which category an issue type belongs to.
     */
    protected function resolveCategory(string $issueType, array $categories): ?string
    {
        foreach ($categories as $category => $types) {
            if (in_array($issueType, $types, true)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Get scoring configuration with defaults.
     */
    protected function getConfig(): array
    {
        $defaults = [
            'penalties' => [
                'developer_leftover' => 12,
                'status_4xx_internal' => 10,
                'status_5xx' => 10,
                'connection_error' => 10,
                'form_endpoint_404' => 10,
                'status_4xx_external_platform' => 5,
                'malformed_url' => 8,
                'excessive_redirects' => 5,
                'http_on_https' => 4,
                'redirect_chain' => 3,
                'timeout' => 3,
                'bot_protection' => 2,
                'rate_limited' => 1,
            ],
            'confidence_multipliers' => [
                'high' => 1.0,
                'medium' => 0.6,
                'low' => 0.3,
            ],
            'category_penalty_multiplier' => 2.5,
            'dampening' => [
                'tier_1_max' => 1,
                'tier_2_max' => 5,
                'tier_2_factor' => 0.5,
                'tier_3_factor' => 0.25,
            ],
            'categories' => [
                'link_integrity' => [
                    'weight' => 0.45,
                    'types' => [
                        'status_4xx_internal', 'status_5xx',
                        'connection_error', 'form_endpoint_404', 'timeout',
                        'status_4xx_external_platform',
                    ],
                ],
                'security_hygiene' => [
                    'weight' => 0.20,
                    'types' => [
                        'http_on_https',
                    ],
                ],
                'technical_hygiene' => [
                    'weight' => 0.15,
                    'types' => [
                        'developer_leftover', 'malformed_url',
                    ],
                ],
                'redirect_health' => [
                    'weight' => 0.10,
                    'types' => [
                        'redirect_chain', 'excessive_redirects',
                    ],
                ],
                'link_verifiability' => [
                    'weight' => 0.10,
                    'types' => [
                        'bot_protection', 'rate_limited',
                    ],
                ],
            ],
            'grades' => [
                'excellent' => 90,
                'good' => 75,
                'needs_attention' => 50,
            ],
        ];

        try {
            $configured = config('scanner.integrity_scoring', []);

            return array_replace_recursive($defaults, $configured ?? []);
        } catch (\Throwable) {
            return $defaults;
        }
    }
}
