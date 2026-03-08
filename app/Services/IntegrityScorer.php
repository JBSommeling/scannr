<?php

namespace App\Services;

use App\DTO\IntegrityScoreResult;
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

        // Track penalties per category for sub-scores
        $categoryPenalties = [
            'link_integrity' => 0,
            'security_hygiene' => 0,
            'technical_hygiene' => 0,
            'redirect_health' => 0,
        ];

        // Extract category type mappings and weights from config
        $categoryTypes = [];
        $categoryWeights = [];
        foreach ($categories as $catName => $catConfig) {
            $categoryTypes[$catName] = $catConfig['types'] ?? [];
            $categoryWeights[$catName] = $catConfig['weight'] ?? 0;
        }

        $appliedPenalties = [];
        $totalPenalty = 0;

        // Summary counters
        $criticalIssues = 0;
        $warnings = 0;
        $manualVerification = 0;

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

            // Determine the penalizing issue type for this result
            $issueType = $this->resolveIssueType($flags, $type, $status);

            if ($issueType === null) {
                continue;
            }

            $basePenalty = $penaltyWeights[$issueType] ?? 0;
            if ($basePenalty <= 0) {
                continue;
            }

            // Confidence multiplier
            $multiplier = $confidenceMultipliers[$confidence] ?? 1.0;

            // Duplicate dampening
            $occurrenceCounts[$issueType] = ($occurrenceCounts[$issueType] ?? 0) + 1;
            $count = $occurrenceCounts[$issueType];
            $dampeningFactor = $this->calculateDampening($count, $dampening);

            $effectivePenalty = $basePenalty * $multiplier * $dampeningFactor;

            $totalPenalty += $effectivePenalty;

            // Assign to category
            $category = $this->resolveCategory($issueType, $categoryTypes);
            if ($category !== null) {
                $categoryPenalties[$category] += $effectivePenalty;
            }

            $appliedPenalties[] = [
                'flag' => $issueType,
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
    protected function resolveIssueType(array $flags, string $type, string|int $status): ?string
    {
        $hasFormEndpoint = in_array('form_endpoint', $flags, true);
        $hasStatus4xx = in_array('status_4xx', $flags, true);
        $hasBotProtection = in_array('bot_protection', $flags, true);
        $hasExternalPlatform = in_array('external_platform', $flags, true);

        // Skip healthy form endpoints (non-404 4xx)
        if ($hasFormEndpoint && $hasStatus4xx) {
            $statusInt = is_numeric($status) ? (int) $status : 0;
            $healthyFormStatuses = [400, 401, 403, 405, 422, 429];
            if (in_array($statusInt, $healthyFormStatuses, true)) {
                return null;
            }
            // Form endpoint with 404 = broken
            return 'form_endpoint_404';
        }

        // Priority order: most severe first
        if (in_array('developer_leftover', $flags, true)) {
            return 'developer_leftover';
        }

        if (in_array('connection_error', $flags, true) && ! $hasExternalPlatform) {
            return 'connection_error';
        }

        if (in_array('status_5xx', $flags, true)) {
            return 'status_5xx';
        }

        // Internal 4xx without bot protection
        if ($hasStatus4xx && $type === 'internal' && ! $hasBotProtection) {
            return 'status_4xx_internal';
        }

        if (in_array('malformed_url', $flags, true)) {
            return 'malformed_url';
        }

        if (in_array('excessive_redirects', $flags, true)) {
            return 'excessive_redirects';
        }

        if (in_array('http_on_https', $flags, true)) {
            return 'http_on_https';
        }

        if (in_array('timeout', $flags, true)) {
            return 'timeout';
        }

        if (in_array('redirect_chain', $flags, true)) {
            return 'redirect_chain';
        }

        if ($hasBotProtection) {
            return 'bot_protection';
        }

        if (in_array('rate_limited', $flags, true)) {
            return 'rate_limited';
        }

        // External 4xx with platform flag but no bot protection — still a warning-level issue
        if ($hasStatus4xx && $hasExternalPlatform) {
            return 'bot_protection';
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
                    'weight' => 0.55,
                    'types' => [
                        'status_4xx_internal', 'status_5xx',
                        'connection_error', 'form_endpoint_404', 'timeout',
                    ],
                ],
                'security_hygiene' => [
                    'weight' => 0.20,
                    'types' => [
                        'http_on_https', 'bot_protection', 'rate_limited',
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
