<?php

namespace Tests\Unit;

use Scannr\DTO\ScanConfig;
use Scannr\Jobs\ScanSiteJob;
use Scannr\Models\ScanResult;
use Scannr\Services\CrawlerService;
use Scannr\Services\ResultFormatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanSiteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_transitions_status_to_running_then_completed(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 1,
            maxUrls: 5,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: false,
            customTrackingParams: [],
        );

        $scanResult = ScanResult::create([
            'url' => 'https://example.com',
            'config' => $config->toArray(),
            'status' => 'pending',
        ]);

        $mockResults = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'sourceElement' => 'a',
                'status' => 200,
                'type' => 'text/html',
                'isOk' => true,
                'redirectChain' => [],
                'hasHttpsDowngrade' => false,
            ],
        ];

        $jsonArray = [
            'summary' => ['totalScanned' => 1, 'ok' => 1, 'broken' => 0],
            'results' => $mockResults,
            'brokenLinks' => [],
        ];

        $crawlerService = $this->mock(CrawlerService::class);
        $crawlerService->shouldReceive('crawl')
            ->once()
            ->andReturn([
                'results' => $mockResults,
                'aborted' => false,
                'error' => null,
            ]);

        $resultFormatter = $this->mock(ResultFormatterService::class);
        $resultFormatter->shouldReceive('toJsonArray')
            ->once()
            ->andReturn($jsonArray);

        $job = new ScanSiteJob($scanResult);
        $job->handle($crawlerService, $resultFormatter);

        $scanResult->refresh();

        $this->assertEquals('completed', $scanResult->status);
        $this->assertNotNull($scanResult->started_at);
        $this->assertNotNull($scanResult->completed_at);
        $this->assertNotNull($scanResult->results);

        $decoded = json_decode($scanResult->results, true);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertArrayHasKey('brokenLinks', $decoded);
    }

    public function test_job_stores_json_results(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 1,
            maxUrls: 5,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: false,
            customTrackingParams: [],
        );

        $scanResult = ScanResult::create([
            'url' => 'https://example.com',
            'config' => $config->toArray(),
            'status' => 'pending',
        ]);

        $mockResults = [
            [
                'url' => 'https://example.com/broken',
                'sourcePage' => 'https://example.com',
                'sourceElement' => 'a',
                'status' => 404,
                'type' => 'text/html',
                'isOk' => false,
                'redirectChain' => [],
                'hasHttpsDowngrade' => false,
            ],
        ];

        $expectedJson = [
            'summary' => ['totalScanned' => 1, 'ok' => 0, 'broken' => 1],
            'results' => $mockResults,
            'brokenLinks' => $mockResults,
        ];

        $crawlerService = $this->mock(CrawlerService::class);
        $crawlerService->shouldReceive('crawl')->andReturn([
            'results' => $mockResults,
            'aborted' => false,
            'error' => null,
        ]);

        $resultFormatter = $this->mock(ResultFormatterService::class);
        $resultFormatter->shouldReceive('toJsonArray')->andReturn($expectedJson);

        $job = new ScanSiteJob($scanResult);
        $job->handle($crawlerService, $resultFormatter);

        $scanResult->refresh();

        $decoded = json_decode($scanResult->results, true);
        $this->assertEquals(1, $decoded['summary']['broken']);
        $this->assertCount(1, $decoded['brokenLinks']);
    }

    public function test_job_marks_status_failed_on_exception(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 1,
            maxUrls: 5,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: false,
            customTrackingParams: [],
        );

        $scanResult = ScanResult::create([
            'url' => 'https://example.com',
            'config' => $config->toArray(),
            'status' => 'pending',
        ]);

        $job = new ScanSiteJob($scanResult);
        $job->failed(new \RuntimeException('Connection timed out'));

        $scanResult->refresh();

        $this->assertEquals('failed', $scanResult->status);
        $this->assertEquals('Connection timed out', $scanResult->error);
        $this->assertNotNull($scanResult->completed_at);
    }

    public function test_job_reconstructs_config_from_stored_array(): void
    {
        $originalConfig = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 5,
            maxUrls: 100,
            timeout: 10,
            scanElements: ['a', 'img'],
            statusFilter: 'broken',
            elementFilter: 'a',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: true,
            customTrackingParams: ['ref'],
            useJsRendering: true,
            respectRobots: false,
        );

        $scanResult = ScanResult::create([
            'url' => 'https://example.com',
            'config' => $originalConfig->toArray(),
            'status' => 'pending',
        ]);

        // Reload from DB to ensure JSON serialization round-trip works
        $scanResult->refresh();

        $restored = ScanConfig::fromArray($scanResult->config)['config'];

        $this->assertEquals($originalConfig->baseUrl, $restored->baseUrl);
        $this->assertEquals($originalConfig->maxDepth, $restored->maxDepth);
        $this->assertEquals($originalConfig->maxUrls, $restored->maxUrls);
        $this->assertEquals($originalConfig->scanElements, $restored->scanElements);
        $this->assertEquals($originalConfig->useSitemap, $restored->useSitemap);
        $this->assertEquals($originalConfig->useJsRendering, $restored->useJsRendering);
        $this->assertEquals($originalConfig->respectRobots, $restored->respectRobots);
    }
}
