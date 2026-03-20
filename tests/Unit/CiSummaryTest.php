<?php

namespace Tests\Unit;

use Scannr\Contracts\OutputInterface;
use Tests\TestCase;

class CiSummaryTest extends TestCase
{
    private const SUMMARY_FILE = '/tmp/scannr-ci-summary.json';

    protected function tearDown(): void
    {
        if (file_exists(self::SUMMARY_FILE)) {
            unlink(self::SUMMARY_FILE);
        }

        parent::tearDown();
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

    private function readSummary(): array
    {
        $this->assertFileExists(self::SUMMARY_FILE, 'CI summary file was not written by artisan');

        return json_decode(file_get_contents(self::SUMMARY_FILE), true);
    }

    // ==================================
    // File is written after scan
    // ==================================

    public function test_ci_summary_file_is_written_after_scan(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult([], 'info', 'high'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $this->assertFileExists(self::SUMMARY_FILE);
    }

    // ==================================
    // Summary fields are present
    // ==================================

    public function test_ci_summary_contains_score_field(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [$this->makeResult([], 'info', 'high')],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertArrayHasKey('score', $summary);
        $this->assertIsNumeric($summary['score']);
    }

    public function test_ci_summary_contains_grade_field(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [$this->makeResult([], 'info', 'high')],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertArrayHasKey('grade', $summary);
        $this->assertIsString($summary['grade']);
        $this->assertNotEmpty($summary['grade']);
    }

    public function test_ci_summary_contains_broken_count_field(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [$this->makeResult([], 'info', 'high')],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertArrayHasKey('broken_count', $summary);
        $this->assertIsInt($summary['broken_count']);
    }

    public function test_ci_summary_contains_critical_count_field(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [$this->makeResult([], 'info', 'high')],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertArrayHasKey('critical_count', $summary);
        $this->assertIsInt($summary['critical_count']);
    }

    // ==================================
    // Summary values are correct
    // ==================================

    public function test_ci_summary_grade_is_excellent_for_healthy_site(): void
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

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertEquals('Excellent', $summary['grade']);
        $this->assertEquals(100.0, $summary['score']);
    }

    public function test_ci_summary_grade_is_not_excellent_with_critical_issues(): void
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

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertNotEquals('Excellent', $summary['grade']);
        $this->assertLessThan(100, $summary['score']);
    }

    public function test_ci_summary_broken_count_reflects_broken_links(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/b'),
                    $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/ok'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertEquals(2, $summary['broken_count']);
    }

    public function test_ci_summary_critical_count_reflects_critical_issues(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult(['status_4xx'], 'critical', 'high', 'internal', 404, 'https://example.com/a'),
                    $this->makeResult(['status_5xx'], 'critical', 'high', 'internal', 500, 'https://example.com/b'),
                    $this->makeResult([], 'info', 'high', 'internal', 200, 'https://example.com/ok'),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertEquals(2, $summary['critical_count']);
    }

    public function test_ci_summary_broken_count_is_zero_for_healthy_site(): void
    {
        $this->mock(\Scannr\Services\CrawlerService::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn([
                'results' => [
                    $this->makeResult([], 'info', 'high', 'internal', 200),
                ],
                'aborted' => false,
            ]);
        });

        $this->artisan('site:scan', ['url' => 'https://example.com', '--format' => 'json']);

        $summary = $this->readSummary();
        $this->assertEquals(0, $summary['broken_count']);
        $this->assertEquals(0, $summary['critical_count']);
    }
}
