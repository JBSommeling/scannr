<?php

namespace Tests\Unit;

use Scannr\Services\IntegrityScorer;
use Scannr\Services\ScanStatistics;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrityScorerTest extends TestCase
{
    private IntegrityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new IntegrityScorer(new ScanStatistics);
    }

    /** Helper to build a result item with analysis data. */
    private function makeResult(array $flags, string $severity, string $confidence, string $type = 'internal', string|int $status = 200, string $url = 'https://example.com/page'): array
    {
        return [
            'url' => $url,
            'sourcePage' => 'https://example.com',
            'status' => (string) $status,
            'type' => $type,
            'sourceElement' => 'a',
            'analysis' => [
                'flags' => $flags,
                'severity' => $severity,
                'confidence' => $confidence,
                'verification' => $confidence === 'low' ? 'recommended' : ($confidence === 'medium' ? 'optional' : 'none'),
            ],
        ];
    }

    public function test_empty_results_returns_perfect_score(): void
    {
        $result = $this->scorer->calculate([]);

        $this->assertEquals(100, $result->overallScore);
        $this->assertEquals('Excellent', $result->grade);
        $this->assertEquals('🟢', $result->gradeEmoji);
        $this->assertEmpty($result->penalties);
    }

    public function test_all_ok_results_returns_perfect_score(): void
    {
        $results = [
            $this->makeResult(['static_html'], 'info', 'high'),
            $this->makeResult(['static_html'], 'info', 'high'),
            $this->makeResult(['static_html'], 'info', 'high'),
        ];

        $result = $this->scorer->calculate($results);

        $this->assertEquals(100, $result->overallScore);
        $this->assertEquals('Excellent', $result->grade);
    }

    public function test_single_broken_internal_link(): void
    {
        $results = [
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ];

        $result = $this->scorer->calculate($results);

        // Penalty: -10 direct subtraction → score = 90
        $this->assertEquals(90, $result->overallScore);
        $this->assertEquals('Excellent', $result->grade);
        $this->assertCount(1, $result->penalties);
        $this->assertEquals(10, $result->penalties[0]['effectivePenalty']);
    }

    public function test_mixed_issues_example_from_spec(): void
    {
        $results = [
            // 1 broken internal (cv.pdf) — penalty: -10 × 1.0 × 1.0 = -10
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/cv.pdf'),
            // 1 developer leftover (localhost) — penalty: -12 × 1.0 × 1.0 = -12
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost:3000'),
            // 1 malformed URL (${r}) — penalty: -8 × 1.0 × 1.0 = -8 (HIGH confidence from evaluator)
            $this->makeResult(['malformed_url', 'indirect_reference'], 'warning', 'high', 'internal', 0, 'https://example.com/${r}'),
            // 2 bot protected links — penalty: -2 × 0.3 (LOW) × 1.0 = -0.6 (first), -2 × 0.3 × 0.5 = -0.3 (second, dampened)
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405, 'https://linkedin.com/in/user'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://github.com/user'),
            // 1 redirect chain — penalty: -3 × 0.6 (MEDIUM) × 1.0 = -1.8
            $this->makeResult(['static_html', 'redirect_chain'], 'warning', 'medium', 'internal', 200, 'https://example.com/old-page'),
        ];

        $result = $this->scorer->calculate($results);

        // Total: -10 - 12 - 8 - 0.6 - 0.3 - 1.8 = -32.7 → score ≈ 67.3
        $this->assertGreaterThan(65, $result->overallScore);
        $this->assertLessThan(70, $result->overallScore);
    }

    public function test_high_confidence_applies_full_penalty(): void
    {
        // developer_leftover with HIGH confidence = full penalty
        $result = $this->scorer->calculate([
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost'),
        ]);

        // -12 × 1.0 = -12 → score 88
        $this->assertEquals(88, $result->overallScore);
    }

    public function test_malformed_url_with_high_confidence_full_penalty(): void
    {
        // malformed_url gets HIGH confidence from evaluator (not LOW like indirect_reference)
        $result = $this->scorer->calculate([
            $this->makeResult(['malformed_url'], 'warning', 'high', 'internal', 0, 'https://example.com/${r}'),
        ]);

        // -8 × 1.0 = -8 → score 92
        $this->assertEquals(92, $result->overallScore);
    }

    public function test_low_confidence_reduces_penalty(): void
    {
        // bot_protection with LOW confidence — penalty is reduced
        $result = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405, 'https://linkedin.com/in/user'),
        ]);

        // bot_protection: -2 × 0.3 (LOW) = -0.6 → score 99.4
        $this->assertEquals(99.4, $result->overallScore);
    }

    public function test_category_scores_amplified_by_multiplier(): void
    {
        // Single broken internal: overall penalty = -10, category penalty = -10 × 2.5 = -25
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ]);

        $this->assertEquals(90, $result->overallScore);
        $this->assertEquals(75, $result->categoryScores['link_integrity']);
        // Unaffected categories stay at 100
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);
        $this->assertEquals(100, $result->categoryScores['technical_hygiene']);
        $this->assertEquals(100, $result->categoryScores['redirect_health']);
        $this->assertEquals(100, $result->categoryScores['link_verifiability']);
    }

    public function test_category_scores_clamped_at_zero(): void
    {
        // developer_leftover (-12) + malformed_url (-8) = -20 × 2.5 = -50 → technical = 50
        $result = $this->scorer->calculate([
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost'),
            $this->makeResult(['malformed_url'], 'warning', 'high', 'internal', 0, 'https://example.com/${r}'),
        ]);

        $this->assertEquals(50, $result->categoryScores['technical_hygiene']);
        $this->assertGreaterThanOrEqual(0, $result->categoryScores['technical_hygiene']);
    }

    public function test_duplicate_dampening_prevents_score_destruction(): void
    {
        // 20 broken internal links should NOT result in -200 penalty
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, "https://example.com/page-{$i}");
        }

        $result = $this->scorer->calculate($results);

        // Without dampening: 20 × 10 = 200 → score 0
        // With dampening: 1×10 + 4×5 + 15×2.5 = 10 + 20 + 37.5 = 67.5 → score 32.5
        $this->assertGreaterThan(0, $result->overallScore);
        $this->assertGreaterThan(25, $result->overallScore);
    }

    public function test_confidence_multiplier_reduces_low_confidence_penalty(): void
    {
        // HIGH confidence
        $highResult = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ]);

        // LOW confidence (same issue)
        $lowResult = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx', 'bot_protection', 'external_platform'], 'warning', 'low', 'external', 403),
        ]);

        // Low confidence penalty should be much smaller
        $highPenalty = 100 - $highResult->overallScore;
        $lowPenalty = 100 - $lowResult->overallScore;

        $this->assertGreaterThan($lowPenalty, $highPenalty);
    }

    public function test_category_scores_calculated_correctly(): void
    {
        $results = [
            // Link integrity issue
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
            // Security issue
            $this->makeResult(['http_on_https', 'redirect_chain'], 'warning', 'high', 'internal', 200),
            // Redirect issue
            $this->makeResult(['excessive_redirects', 'redirect_chain'], 'warning', 'medium', 'internal', 200),
        ];

        $result = $this->scorer->calculate($results);

        $this->assertArrayHasKey('link_integrity', $result->categoryScores);
        $this->assertArrayHasKey('security_hygiene', $result->categoryScores);
        $this->assertArrayHasKey('technical_hygiene', $result->categoryScores);
        $this->assertArrayHasKey('redirect_health', $result->categoryScores);
        $this->assertArrayHasKey('link_verifiability', $result->categoryScores);

        // Link integrity should have the broken internal penalty
        $this->assertLessThan(100, $result->categoryScores['link_integrity']);
        // Security hygiene should have the http_on_https penalty
        $this->assertLessThan(100, $result->categoryScores['security_hygiene']);
        // Redirect health should have the excessive redirect penalty
        $this->assertLessThan(100, $result->categoryScores['redirect_health']);
    }

    public function test_grade_thresholds(): void
    {
        // Excellent (100)
        $result = $this->scorer->calculate([]);
        $this->assertEquals('Excellent', $result->grade);
        $this->assertEquals('green', $result->gradeColor);

        // Good (single broken internal → score = 90)
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ]);
        $this->assertEquals(90, $result->overallScore);
        $this->assertEquals('Excellent', $result->grade);

        // Two issues: broken internal (-10) + developer leftover (-12) = -22 → score = 78
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost'),
        ]);
        $this->assertEquals(78, $result->overallScore);
        $this->assertEquals('Good', $result->grade);
        $this->assertEquals('yellow', $result->gradeColor);
    }

    public function test_score_never_below_zero(): void
    {
        // Create many severe issues
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, "http://localhost:{$i}");
            $results[] = $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, "https://example.com/broken-{$i}");
            $results[] = $this->makeResult(['connection_error'], 'critical', 'high', 'internal', 'Error', "https://dead-{$i}.example.com");
        }

        $result = $this->scorer->calculate($results);

        $this->assertGreaterThanOrEqual(0, $result->overallScore);
    }

    public function test_healthy_form_endpoints_are_not_penalized(): void
    {
        $results = [
            // Form endpoint returning 422 (healthy — validation error)
            [
                'url' => 'https://example.com/contact',
                'sourcePage' => 'https://example.com',
                'status' => '422',
                'type' => 'internal',
                'sourceElement' => 'form',
                'analysis' => [
                    'flags' => ['form_endpoint', 'status_4xx'],
                    'severity' => 'info',
                    'confidence' => 'high',
                    'verification' => 'none',
                ],
            ],
        ];

        $result = $this->scorer->calculate($results);

        $this->assertEquals(100, $result->overallScore);
        $this->assertEmpty($result->penalties);
    }

    public function test_form_endpoint_404_is_penalized(): void
    {
        $results = [
            [
                'url' => 'https://example.com/nonexistent-form',
                'sourcePage' => 'https://example.com',
                'status' => '404',
                'type' => 'internal',
                'sourceElement' => 'form',
                'analysis' => [
                    'flags' => ['form_endpoint', 'status_4xx'],
                    'severity' => 'critical',
                    'confidence' => 'high',
                    'verification' => 'none',
                ],
            ],
        ];

        $result = $this->scorer->calculate($results);

        // form_endpoint_404 penalty = -10 → score = 90
        $this->assertEquals(90, $result->overallScore);
    }

    public function test_summary_counts(): void
    {
        $results = [
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
            $this->makeResult(['redirect_chain'], 'warning', 'medium', 'internal', 200),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405),
        ];

        $result = $this->scorer->calculate($results);

        $this->assertEquals(1, $result->summary['criticalIssues']);
        $this->assertEquals(2, $result->summary['warnings']);
        $this->assertEquals(1, $result->summary['brokenLinks']); // 404 internal only; 405 external is bot-protected (unverifiable, not broken)
        $this->assertEquals(1, $result->summary['manualVerification']);
    }

    public function test_timeout_counts_as_broken_link(): void
    {
        // A timed-out link is broken from the visitor's perspective and should
        // be included in the brokenLinks summary count.
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
            $this->makeResult(['timeout'], 'warning', 'high', 'external', 'timeout', 'http://localhost:3200/oauth2-redirect.html'),
        ]);

        $this->assertEquals(2, $result->summary['brokenLinks']);
    }


    public function test_to_array_serialization(): void
    {
        $result = $this->scorer->calculate([]);

        $array = $result->toArray();

        $this->assertArrayHasKey('overallScore', $array);
        $this->assertArrayHasKey('grade', $array);
        $this->assertArrayHasKey('gradeColor', $array);
        $this->assertArrayHasKey('gradeEmoji', $array);
        $this->assertArrayHasKey('categoryScores', $array);
        $this->assertArrayHasKey('penalties', $array);
        $this->assertArrayHasKey('summary', $array);
    }

    public function test_developer_leftover_has_highest_penalty(): void
    {
        $localhostResult = $this->scorer->calculate([
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost:3000'),
        ]);

        $brokenResult = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ]);

        $localhostPenalty = 100 - $localhostResult->overallScore;
        $brokenPenalty = 100 - $brokenResult->overallScore;

        // Developer leftover (-12) > broken internal (-10)
        $this->assertGreaterThan($brokenPenalty, $localhostPenalty);
    }

    public function test_info_severity_items_have_no_penalty(): void
    {
        $results = [
            $this->makeResult(['static_html'], 'info', 'high'),
            $this->makeResult(['runtime_rendered'], 'info', 'high'),
            $this->makeResult(['indirect_reference'], 'info', 'low'),
            $this->makeResult(['detected_in_js_bundle'], 'info', 'medium'),
        ];

        $result = $this->scorer->calculate($results);

        $this->assertEquals(100, $result->overallScore);
    }

    // =============================================
    // Exact scoring validation tests
    // =============================================

    public function test_exact_penalty_weights(): void
    {
        // Verify each penalty type applies its exact base weight at HIGH confidence
        $cases = [
            ['developer_leftover', 12],
            ['status_4xx_internal', 10],  // resolved from status_4xx + internal
            ['malformed_url', 8],
            ['http_on_https', 4],
            ['redirect_chain', 3],
        ];

        foreach ($cases as [$issueType, $expectedPenalty]) {
            $flags = match ($issueType) {
                'developer_leftover' => ['developer_leftover'],
                'status_4xx_internal' => ['static_html', 'status_4xx'],
                'malformed_url' => ['malformed_url'],
                'http_on_https' => ['http_on_https'],
                'redirect_chain' => ['redirect_chain'],
            };

            $type = $issueType === 'status_4xx_internal' ? 'internal' : 'internal';
            $status = $issueType === 'status_4xx_internal' ? 404 : 200;

            $result = $this->scorer->calculate([
                $this->makeResult($flags, 'warning', 'high', $type, $status),
            ]);

            $this->assertEquals(
                100 - $expectedPenalty,
                $result->overallScore,
                "Failed for {$issueType}: expected penalty of {$expectedPenalty}"
            );
        }
    }

    public function test_exact_confidence_multipliers(): void
    {
        // Same issue (bot_protection, base -2) at each confidence level
        $highResult = $this->scorer->calculate([
            $this->makeResult(['bot_protection'], 'warning', 'high', 'external', 403),
        ]);
        $mediumResult = $this->scorer->calculate([
            $this->makeResult(['bot_protection'], 'warning', 'medium', 'external', 403),
        ]);
        $lowResult = $this->scorer->calculate([
            $this->makeResult(['bot_protection'], 'warning', 'low', 'external', 403),
        ]);

        // HIGH: -2 × 1.0 = -2 → 98
        $this->assertEquals(98, $highResult->overallScore);
        // MEDIUM: -2 × 0.6 = -1.2 → 98.8
        $this->assertEquals(98.8, $mediumResult->overallScore);
        // LOW: -2 × 0.3 = -0.6 → 99.4
        $this->assertEquals(99.4, $lowResult->overallScore);
    }

    public function test_exact_dampening_tiers(): void
    {
        // 7 identical broken internal links to test all dampening tiers
        $results = [];
        for ($i = 0; $i < 7; $i++) {
            $results[] = $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, "https://example.com/page-{$i}");
        }

        $result = $this->scorer->calculate($results);

        // Tier 1 (1st): 10 × 1.0 = 10
        // Tier 2 (2nd-5th): 4 × 10 × 0.5 = 20
        // Tier 3 (6th-7th): 2 × 10 × 0.25 = 5
        // Total: 35 → score = 65
        $this->assertEquals(65, $result->overallScore);
    }

    public function test_exact_category_multiplier_effect(): void
    {
        // http_on_https: base -4, HIGH confidence → effective -4
        // Category multiplier 2.5: security_hygiene = 100 - (4 × 2.5) = 90
        $result = $this->scorer->calculate([
            $this->makeResult(['http_on_https'], 'warning', 'high', 'internal', 200),
        ]);

        $this->assertEquals(96, $result->overallScore);
        $this->assertEquals(90, $result->categoryScores['security_hygiene']);
        $this->assertEquals(100, $result->categoryScores['link_integrity']);
        $this->assertEquals(100, $result->categoryScores['technical_hygiene']);
        $this->assertEquals(100, $result->categoryScores['redirect_health']);
        $this->assertEquals(100, $result->categoryScores['link_verifiability']);
    }

    public function test_exact_realistic_scan_scenario(): void
    {
        // Simulates a real scan like sommeling.dev:
        // - 1 broken internal (cv.pdf, 404, HIGH)
        // - 1 developer leftover (localhost, HIGH)
        // - 1 malformed URL (${r}, HIGH)
        // - 2 bot protected (LOW)
        // - 1 redirect chain (MEDIUM)
        $results = [
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/cv.pdf'),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost'),
            $this->makeResult(['malformed_url'], 'warning', 'high', 'internal', 0, 'https://example.com/${r}'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405, 'https://linkedin.com/in/user'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://github.com/user'),
            $this->makeResult(['static_html', 'redirect_chain'], 'warning', 'medium', 'internal', 200, 'https://example.com/old'),
        ];

        $result = $this->scorer->calculate($results);

        // Penalty breakdown:
        // status_4xx_internal: -10 × 1.0 × 1.0 = -10.0
        // developer_leftover: -12 × 1.0 × 1.0 = -12.0
        // malformed_url:      -8 × 1.0 × 1.0  = -8.0
        // bot_protection #1:  -2 × 0.3 × 1.0  = -0.6
        // bot_protection #2:  -2 × 0.3 × 0.5  = -0.3
        // redirect_chain:     -3 × 0.6 × 1.0  = -1.8
        // Total: -32.7

        // Overall score
        $this->assertEquals(67.3, $result->overallScore);
        $this->assertEquals('Needs Attention', $result->grade);
        $this->assertEquals('orange', $result->gradeColor);
        $this->assertEquals('🟠', $result->gradeEmoji);

        // Category scores (penalty × 2.5 multiplier)
        // link_integrity:      100 - (10 × 2.5)           = 75.0
        // link_verifiability:  100 - ((0.6 + 0.3) × 2.5)  = 97.75 → 97.8 (rounded)
        // technical_hygiene:   100 - ((12 + 8) × 2.5)     = 50.0
        // redirect_health:     100 - (1.8 × 2.5)          = 95.5
        $this->assertEquals(75, $result->categoryScores['link_integrity']);
        $this->assertEquals(97.8, $result->categoryScores['link_verifiability']);
        $this->assertEquals(50, $result->categoryScores['technical_hygiene']);
        $this->assertEquals(95.5, $result->categoryScores['redirect_health']);

        // Summary counts
        $this->assertEquals(2, $result->summary['criticalIssues']);
        $this->assertEquals(4, $result->summary['warnings']);
        $this->assertEquals(2, $result->summary['brokenLinks']); // 404 internal + status 0 (malformed); bot-protected 405+403 are unverifiable, not broken
        $this->assertEquals(2, $result->summary['manualVerification']);

        // Penalty count
        $this->assertCount(6, $result->penalties);
    }

    public function test_exact_status_5xx_penalty_with_medium_confidence(): void
    {
        // 5xx gets MEDIUM confidence from evaluator → -10 × 0.6 = -6
        $result = $this->scorer->calculate([
            $this->makeResult(['status_5xx'], 'critical', 'medium', 'internal', 500),
        ]);

        $this->assertEquals(94, $result->overallScore);
        // Category: link_integrity = 100 - (6 × 2.5) = 85
        $this->assertEquals(85, $result->categoryScores['link_integrity']);
    }

    public function test_exact_connection_error_penalty(): void
    {
        // connection_error: -10 × 1.0 = -10
        $result = $this->scorer->calculate([
            $this->makeResult(['connection_error'], 'critical', 'high', 'internal', 'error'),
        ]);

        $this->assertEquals(90, $result->overallScore);
        $this->assertEquals(75, $result->categoryScores['link_integrity']);
    }

    public function test_exact_excessive_redirects_penalty(): void
    {
        // excessive_redirects: -5 × 0.6 (MEDIUM) = -3
        $result = $this->scorer->calculate([
            $this->makeResult(['excessive_redirects', 'redirect_chain'], 'warning', 'medium', 'internal', 200),
        ]);

        $this->assertEquals(97, $result->overallScore);
        // Category: redirect_health = 100 - (3 × 2.5) = 92.5
        $this->assertEquals(92.5, $result->categoryScores['redirect_health']);
    }

    public function test_exact_multiple_issues_same_category(): void
    {
        // Two different issues in link_integrity:
        // broken internal (-10) + connection error (-10) = -20 total
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
            $this->makeResult(['connection_error'], 'critical', 'high', 'internal', 'error', 'https://example.com/b'),
        ]);

        $this->assertEquals(80, $result->overallScore);
        // link_integrity = 100 - (20 × 2.5) = 50
        $this->assertEquals(50, $result->categoryScores['link_integrity']);
    }

    public function test_bot_protection_penalizes_link_verifiability_not_security(): void
    {
        // A single bot_protection hit should only affect link_verifiability,
        // leaving security_hygiene untouched.
        $result = $this->scorer->calculate([
            $this->makeResult(['bot_protection'], 'warning', 'high', 'external', 403, 'https://github.com/user'),
        ]);

        // bot_protection: -2 × 1.0 × 1.0 = -2 → overall 98
        $this->assertEquals(98, $result->overallScore);
        // link_verifiability = 100 - (2 × 2.5) = 95
        $this->assertEquals(95, $result->categoryScores['link_verifiability']);
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);
        $this->assertEquals(100, $result->categoryScores['link_integrity']);
    }

    public function test_rate_limited_penalizes_link_verifiability_not_security(): void
    {
        // rate_limited should land in link_verifiability, not security_hygiene.
        $result = $this->scorer->calculate([
            $this->makeResult(['rate_limited'], 'warning', 'high', 'external', 429, 'https://api.example.com'),
        ]);

        // rate_limited: -1 × 1.0 × 1.0 = -1 → overall 99
        $this->assertEquals(99, $result->overallScore);
        // link_verifiability = 100 - (1 × 2.5) = 97.5
        $this->assertEquals(97.5, $result->categoryScores['link_verifiability']);
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);
    }

    public function test_http_on_https_stays_in_security_hygiene(): void
    {
        // http_on_https should still affect security_hygiene, not link_verifiability.
        $result = $this->scorer->calculate([
            $this->makeResult(['http_on_https'], 'warning', 'high', 'internal', 200),
        ]);

        $this->assertLessThan(100, $result->categoryScores['security_hygiene']);
        $this->assertEquals(100, $result->categoryScores['link_verifiability']);
    }

    public function test_link_verifiability_dampening_with_multiple_bot_protection(): void
    {
        // Multiple bot_protection hits should dampen within link_verifiability.
        $result = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://github.com/a'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405, 'https://linkedin.com/b'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://twitter.com/c'),
        ]);

        // bot_protection #1: -2 × 0.3 × 1.0  = -0.6  (tier 1)
        // bot_protection #2: -2 × 0.3 × 0.5  = -0.3  (tier 2)
        // bot_protection #3: -2 × 0.3 × 0.5  = -0.3  (tier 2)
        // Total: -1.2
        $this->assertEquals(98.8, $result->overallScore);

        // link_verifiability = 100 - (1.2 × 2.5) = 97.0
        $this->assertEquals(97, $result->categoryScores['link_verifiability']);
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);
        $this->assertEquals(100, $result->categoryScores['link_integrity']);
    }

    public function test_link_verifiability_perfect_when_no_bot_or_rate_issues(): void
    {
        // A scan with only link_integrity and technical_hygiene issues
        // should leave link_verifiability at 100.
        $result = $this->scorer->calculate([
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost'),
        ]);

        $this->assertEquals(100, $result->categoryScores['link_verifiability']);
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);
        $this->assertLessThan(100, $result->categoryScores['link_integrity']);
        $this->assertLessThan(100, $result->categoryScores['technical_hygiene']);
    }

    public function test_external_platform_4xx_without_bot_protection_uses_distinct_issue_type(): void
    {
        // external_platform + status_4xx without bot_protection flag = genuine broken external link,
        // should NOT be labelled as bot_protection.
        $result = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'status_4xx'], 'warning', 'high', 'external', 404, 'https://github.com/missing-page'),
        ]);

        // status_4xx_external_platform: -5 × 1.0 × 1.0 = -5 → overall 95
        $this->assertEquals(95, $result->overallScore);

        // Should penalise link_integrity, not link_verifiability
        $this->assertLessThan(100, $result->categoryScores['link_integrity']);
        $this->assertEquals(100, $result->categoryScores['link_verifiability']);
        $this->assertEquals(100, $result->categoryScores['security_hygiene']);

        // link_integrity = 100 - (5 × 2.5) = 87.5
        $this->assertEquals(87.5, $result->categoryScores['link_integrity']);
    }

    public function test_external_platform_4xx_with_bot_protection_still_uses_bot_protection_type(): void
    {
        // When bot_protection flag IS present, the penalty should stay as bot_protection
        // (lower penalty — link is likely fine, scanner was blocked).
        $result = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://linkedin.com/in/user'),
        ]);

        // bot_protection: -2 × 0.3 × 1.0 = -0.6 → overall 99.4
        $this->assertEquals(99.4, $result->overallScore);

        // Should penalise link_verifiability, not link_integrity
        $this->assertEquals(100, $result->categoryScores['link_integrity']);
        $this->assertLessThan(100, $result->categoryScores['link_verifiability']);
    }

    public function test_external_platform_4xx_penalised_more_than_bot_protection(): void
    {
        // A genuine external broken link should carry a higher penalty than a bot-blocked one.
        $brokenResult = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'status_4xx'], 'warning', 'high', 'external', 404),
        ]);
        $botBlockedResult = $this->scorer->calculate([
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'high', 'external', 403),
        ]);

        $brokenPenalty = 100 - $brokenResult->overallScore;
        $botBlockedPenalty = 100 - $botBlockedResult->overallScore;

        $this->assertGreaterThan($botBlockedPenalty, $brokenPenalty);
    }

    public function test_exact_critical_grade_threshold(): void
    {
        // Push score below 50 → Critical grade
        // 3 broken internal (HIGH): -10 + -5 + -5 = -20 (dampened)
        // 2 developer leftover (HIGH): -12 + -6 = -18 (dampened)
        // 1 malformed (HIGH): -8
        // Total: -46 → score 54... still above 50
        // Add more:
        // + connection error: -10
        // Total: -56 → score 44
        $results = [
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/b'),
            $this->makeResult(['static_html', 'status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/c'),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost:3000'),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost:8080'),
            $this->makeResult(['malformed_url'], 'warning', 'high', 'internal', 0, 'https://example.com/${x}'),
            $this->makeResult(['connection_error'], 'critical', 'high', 'internal', 'error', 'https://dead.example.com'),
        ];

        $result = $this->scorer->calculate($results);

        $this->assertLessThan(50, $result->overallScore);
        $this->assertEquals('Critical', $result->grade);
        $this->assertEquals('red', $result->gradeColor);
        $this->assertEquals('🔴', $result->gradeEmoji);
    }

    // =============================================
    // Broken link summary counter — status coverage
    // =============================================

    #[DataProvider('brokenLinkStatusProvider')]
    public function test_status_increments_broken_link_count(string|int $status, array $flags): void
    {
        $result = $this->scorer->calculate([
            $this->makeResult($flags, 'critical', 'high', 'external', $status),
        ]);

        $this->assertEquals(1, $result->summary['brokenLinks'], "Expected status '{$status}' to count as a broken link");
    }

    public static function brokenLinkStatusProvider(): array
    {
        return [
            '404 Not Found'        => [404, ['status_4xx']],
            '410 Gone'             => [410, ['status_4xx']],
            '500 Server Error'     => [500, ['status_5xx']],
            '503 Unavailable'      => [503, ['status_5xx']],
            'timeout'              => ['timeout', ['timeout']],
            'error (connection)'   => ['error', ['connection_error']],
            'status 0 (malformed)' => [0, ['malformed_url']],
        ];
    }

    #[DataProvider('notBrokenLinkStatusProvider')]
    public function test_status_does_not_increment_broken_link_count(string|int $status, array $flags): void
    {
        $result = $this->scorer->calculate([
            $this->makeResult($flags, 'info', 'high', 'internal', $status),
        ]);

        $this->assertEquals(0, $result->summary['brokenLinks'], "Expected status '{$status}' NOT to count as a broken link");
    }

    public static function notBrokenLinkStatusProvider(): array
    {
        return [
            '200 OK'         => [200, []],
            '201 Created'    => [201, []],
            '204 No Content' => [204, []],
        ];
    }

    public function test_healthy_form_endpoint_not_counted_as_broken(): void
    {
        // 405 on a form endpoint means the server is alive — not broken
        $result = $this->scorer->calculate([
            $this->makeResult(['form_endpoint', 'status_4xx'], 'info', 'high', 'internal', 405),
        ]);

        $this->assertEquals(0, $result->summary['brokenLinks']);
    }

    public function test_form_endpoint_404_counts_as_broken(): void
    {
        // 404 on a form endpoint means the endpoint is genuinely missing
        $result = $this->scorer->calculate([
            $this->makeResult(['form_endpoint', 'status_4xx'], 'critical', 'high', 'internal', 404),
        ]);

        $this->assertEquals(1, $result->summary['brokenLinks']);
    }

    public function test_bot_protected_link_not_counted_as_broken(): void
    {
        // A 405 from LinkedIn/similar is bot protection — server is alive, just unverifiable
        $result = $this->scorer->calculate([
            $this->makeResult(['bot_protection', 'external_platform'], 'warning', 'low', 'external', 405),
        ]);

        $this->assertEquals(0, $result->summary['brokenLinks']);
    }

    public function test_bot_protected_403_not_counted_as_broken(): void
    {
        $result = $this->scorer->calculate([
            $this->makeResult(['bot_protection', 'external_platform'], 'warning', 'low', 'external', 403),
        ]);

        $this->assertEquals(0, $result->summary['brokenLinks']);
    }
}
