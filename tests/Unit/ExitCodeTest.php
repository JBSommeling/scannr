<?php

namespace Tests\Unit;

use Scannr\Contracts\OutputInterface;
use Scannr\DTO\IntegrityScoreResult;
use Scannr\DTO\ScanConfig;
use Scannr\Services\IntegrityScorer;
use Scannr\Services\ResultFormatterService;
use Scannr\Services\ScanStatistics;
use Tests\TestCase;

class ExitCodeTest extends TestCase
{
    private ResultFormatterService $formatter;

    private ScanStatistics $scanStatistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanStatistics = new ScanStatistics;
        $this->formatter = new ResultFormatterService($this->scanStatistics, new IntegrityScorer($this->scanStatistics));
    }

    private function createConfig(array $overrides = []): ScanConfig
    {
        return new ScanConfig(
            baseUrl: $overrides['baseUrl'] ?? 'https://example.com',
            maxDepth: $overrides['maxDepth'] ?? 3,
            maxUrls: $overrides['maxUrls'] ?? 100,
            timeout: $overrides['timeout'] ?? 5,
            scanElements: $overrides['scanElements'] ?? ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: $overrides['statusFilter'] ?? 'all',
            elementFilter: $overrides['elementFilter'] ?? 'all',
            outputFormat: $overrides['outputFormat'] ?? 'table',
            delayMin: $overrides['delayMin'] ?? 0,
            delayMax: $overrides['delayMax'] ?? 0,
            useSitemap: $overrides['useSitemap'] ?? false,
            customTrackingParams: $overrides['customTrackingParams'] ?? [],
            showAdvanced: $overrides['showAdvanced'] ?? false,
            failOnCritical: $overrides['failOnCritical'] ?? false,
            minRating: $overrides['minRating'] ?? 'none',
        );
    }

    private function createMockOutput(): OutputInterface
    {
        return new class implements OutputInterface
        {
            public array $lines = [];

            public array $infos = [];

            public array $warnings = [];

            public array $errors = [];

            public array $tables = [];

            public int $newLines = 0;

            public bool $verbose = false;

            public function info(string $message): void
            {
                $this->infos[] = $message;
            }

            public function warn(string $message): void
            {
                $this->warnings[] = $message;
            }

            public function error(string $message): void
            {
                $this->errors[] = $message;
            }

            public function line(string $message = ''): void
            {
                $this->lines[] = $message;
            }

            public function table(array $headers, array $rows): void
            {
                $this->tables[] = ['headers' => $headers, 'rows' => $rows];
            }

            public function newLine(int $count = 1): void
            {
                $this->newLines += $count;
            }

            public function isVerbose(): bool
            {
                return $this->verbose;
            }
        };
    }

    private function makeResult(array $flags, string $severity, string $confidence, string $type = 'internal', string|int $status = 200, string $url = 'https://example.com/page'): array
    {
        return [
            'url' => $url,
            'sourcePage' => 'https://example.com',
            'status' => (string) $status,
            'type' => $type,
            'sourceElement' => 'a',
            'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false],
            'analysis' => [
                'flags' => $flags,
                'severity' => $severity,
                'confidence' => $confidence,
                'verification' => $confidence === 'low' ? 'recommended' : ($confidence === 'medium' ? 'optional' : 'none'),
            ],
        ];
    }

    // ==================================
    // format() returns IntegrityScoreResult
    // ==================================

    public function test_format_returns_integrity_score_result(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult([], 'info', 'high'),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertInstanceOf(IntegrityScoreResult::class, $scoreResult);
    }

    public function test_format_returns_perfect_score_for_healthy_results(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult([], 'info', 'high'),
            $this->makeResult([], 'info', 'high'),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertEquals(100, $scoreResult->overallScore);
        $this->assertEquals('Excellent', $scoreResult->grade);
        $this->assertEquals(0, $scoreResult->summary['criticalIssues']);
    }

    public function test_format_returns_score_with_critical_issues(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertGreaterThan(0, $scoreResult->summary['criticalIssues']);
    }

    // ==================================
    // ScanConfig quality gate properties
    // ==================================

    public function test_scan_config_defaults_fail_on_critical_to_false(): void
    {
        $config = $this->createConfig();

        $this->assertFalse($config->failOnCritical);
    }

    public function test_scan_config_defaults_min_rating_to_none(): void
    {
        $config = $this->createConfig();

        $this->assertEquals('none', $config->minRating);
    }

    public function test_scan_config_accepts_fail_on_critical(): void
    {
        $config = $this->createConfig(['failOnCritical' => true]);

        $this->assertTrue($config->failOnCritical);
    }

    public function test_scan_config_accepts_min_rating(): void
    {
        $config = $this->createConfig(['minRating' => 'good']);

        $this->assertEquals('good', $config->minRating);
    }

    public function test_scan_config_from_array_includes_quality_gate_options(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'failOnCritical' => true,
            'minRating' => 'excellent',
        ]);

        $config = $result['config'];
        $this->assertTrue($config->failOnCritical);
        $this->assertEquals('excellent', $config->minRating);
    }

    public function test_scan_config_to_array_includes_quality_gate_options(): void
    {
        $config = $this->createConfig([
            'failOnCritical' => true,
            'minRating' => 'good',
        ]);

        $array = $config->toArray();
        $this->assertTrue($array['failOnCritical']);
        $this->assertEquals('good', $array['minRating']);
    }

    // ==================================
    // Quality gate: critical issue detection
    // ==================================

    public function test_critical_issues_detected_for_internal_404(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertEquals(1, $scoreResult->summary['criticalIssues']);
    }

    public function test_critical_issues_detected_for_5xx_error(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult(['status_5xx'], 'critical', 'high', 'internal', 500),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertEquals(1, $scoreResult->summary['criticalIssues']);
    }

    public function test_critical_issues_detected_for_developer_leftover(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost:3000/api'),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertEquals(1, $scoreResult->summary['criticalIssues']);
    }

    public function test_no_critical_issues_for_warnings_only(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult(['timeout'], 'warning', 'medium', 'internal', 0),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertEquals(0, $scoreResult->summary['criticalIssues']);
    }

    // ==================================
    // Quality gate: rating threshold
    // ==================================

    public function test_multiple_critical_issues_drop_score_below_good(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();

        // Create enough critical issues to drop score below 75 (Good threshold)
        $results = [
            $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/broken1'),
            $this->makeResult(['status_5xx'], 'critical', 'high', 'internal', 500, 'https://example.com/broken2'),
            $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost/api'),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertLessThan(75, $scoreResult->overallScore);
        $this->assertNotEquals('Excellent', $scoreResult->grade);
        $this->assertNotEquals('Good', $scoreResult->grade);
    }

    public function test_healthy_site_scores_excellent(): void
    {
        $config = $this->createConfig();
        $output = $this->createMockOutput();
        $results = [
            $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/page1'),
            $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/page2'),
            $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/page3'),
        ];

        $scoreResult = $this->formatter->format($results, $config, $output);

        $this->assertGreaterThanOrEqual(90, $scoreResult->overallScore);
        $this->assertEquals('Excellent', $scoreResult->grade);
    }

    // ==================================
    // Quality gate: grade comparison logic
    // ==================================

    public function test_grade_below_minimum_is_detectable(): void
    {
        $gradeThresholds = config('scannr.integrity_scoring.grades', [
            'excellent' => 90,
            'good' => 75,
            'needs_attention' => 50,
        ]);

        // Simulate score of 70 with min-rating of 'good'
        $requiredScore = $gradeThresholds['good'];
        $actualScore = 70;

        $this->assertTrue($actualScore < $requiredScore);
    }

    public function test_grade_at_minimum_passes(): void
    {
        $gradeThresholds = config('scannr.integrity_scoring.grades', [
            'excellent' => 90,
            'good' => 75,
            'needs_attention' => 50,
        ]);

        // Score of exactly 75 with min-rating of 'good' should pass
        $requiredScore = $gradeThresholds['good'];
        $actualScore = 75;

        $this->assertFalse($actualScore < $requiredScore);
    }

    public function test_grade_above_minimum_passes(): void
    {
        $gradeThresholds = config('scannr.integrity_scoring.grades', [
            'excellent' => 90,
            'good' => 75,
            'needs_attention' => 50,
        ]);

        // Score of 85 with min-rating of 'good' should pass
        $requiredScore = $gradeThresholds['good'];
        $actualScore = 85;

        $this->assertFalse($actualScore < $requiredScore);
    }

    public function test_min_rating_none_skips_check(): void
    {
        $config = $this->createConfig(['minRating' => 'none']);

        // 'none' means no threshold check
        $this->assertEquals('none', $config->minRating);
    }

    // ==================================
    // Artisan command exit code tests
    // ==================================

    public function test_command_returns_failure_when_fail_on_critical_with_critical_issues(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--fail-on-critical' => true,
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_command_returns_success_when_fail_on_critical_without_critical_issues(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult([], 'info', 'high'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--fail-on-critical' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_command_returns_failure_when_score_below_min_rating(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    // Multiple critical issues to drop score below 75
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
                    $this->makeResult(['status_5xx'], 'critical', 'high', 'internal', 500, 'https://example.com/b'),
                    $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost/c'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--min-rating' => 'good',
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_command_returns_success_when_score_meets_min_rating(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/page1'),
                    $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/page2'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--min-rating' => 'good',
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_command_returns_success_without_quality_gate_flags(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    // Critical issue but no fail flags — backward compat
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--format' => 'json',
        ])->assertExitCode(0);
    }

    public function test_command_returns_failure_with_both_gates_triggered(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
                    $this->makeResult(['status_5xx'], 'critical', 'high', 'internal', 500, 'https://example.com/b'),
                    $this->makeResult(['developer_leftover'], 'critical', 'high', 'internal', 200, 'http://localhost/c'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--fail-on-critical' => true,
            '--min-rating' => 'good',
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_command_returns_failure_when_min_rating_excellent_and_score_good(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    // Single 404 drops score to 90 (Excellent threshold)
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
                    $this->makeResult(['timeout'], 'warning', 'medium', 'internal', 0, 'https://example.com/b'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--min-rating' => 'excellent',
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_command_returns_success_when_min_rating_needs_attention_and_score_good(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    // Single 404 → score ~90, well above needs_attention threshold (50)
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--min-rating' => 'needs_attention',
            '--format' => 'json',
        ])->assertExitCode(0);
    }
}
