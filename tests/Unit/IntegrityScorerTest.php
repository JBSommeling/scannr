<?php

namespace Tests\Unit;

use App\Services\IntegrityScorer;
use Tests\TestCase;

class IntegrityScorerTest extends TestCase
{
    private IntegrityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new IntegrityScorer;
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
            // 1 malformed URL (${r}) — penalty: -8 × 0.3 × 1.0 = -2.4 (LOW confidence from indirect_reference)
            $this->makeResult(['malformed_url', 'indirect_reference'], 'warning', 'low', 'internal', 0, 'https://example.com/${r}'),
            // 2 bot protected links — penalty: -2 × 0.3 × 1.0 = -0.6 (first), -2 × 0.3 × 0.5 = -0.3 (second, dampened)
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 405, 'https://linkedin.com/in/user'),
            $this->makeResult(['external_platform', 'bot_protection', 'status_4xx'], 'warning', 'low', 'external', 403, 'https://github.com/user'),
            // 1 redirect chain — penalty: -3 × 0.6 × 1.0 = -1.8 (MEDIUM confidence)
            $this->makeResult(['static_html', 'redirect_chain'], 'warning', 'medium', 'internal', 200, 'https://example.com/old-page'),
        ];

        $result = $this->scorer->calculate($results);

        // Direct subtraction: penalties spread across issues
        // Overall should be in the 70-80 range (Good)
        $this->assertGreaterThan(70, $result->overallScore);
        $this->assertLessThan(80, $result->overallScore);
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
        $this->assertEquals(1, $result->summary['manualVerification']);
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
}
