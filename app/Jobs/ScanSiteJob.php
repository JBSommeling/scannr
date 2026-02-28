<?php

namespace App\Jobs;

use App\DTO\ScanConfig;
use App\Models\ScanResult;
use App\Services\CrawlerService;
use App\Services\ResultFormatterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued job that runs a website scan and stores the JSON results.
 *
 * Accepts a ScanResult model (status "pending"), transitions it through
 * running → completed (or failed), and persists the JSON output.
 */
class ScanSiteJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     * Retrying a full crawl is wasteful, so we only try once.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public function retryUntil(): \DateTimeInterface
    {
        $timeout = config('scanner.job_timeout', 600);

        return now()->addSeconds($timeout);
    }

    public function __construct(
        public ScanResult $scanResult,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CrawlerService $crawlerService, ResultFormatterService $resultFormatter): void
    {
        $this->scanResult->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = ScanConfig::fromArray($this->scanResult->config);
        $config = $result['config'];

        try {
            $crawlResult = $crawlerService->crawl($config);
        } catch (Throwable $e) {
            $this->scanResult->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            report($e);

            return;
        }

        // Extract results and error from crawl result
        $results = $crawlResult['results'];
        $error = $crawlResult['error'] ?? null;

        $jsonArray = $resultFormatter->toJsonArray($results, $config, $error);

        // Determine final status based on abort
        $status = ($crawlResult['aborted'] ?? false) ? 'aborted' : 'completed';

        $this->scanResult->update([
            'status' => $status,
            'results' => json_encode($jsonArray, JSON_UNESCAPED_SLASHES),
            'error' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->scanResult->update([
            'status' => 'failed',
            'error' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);
    }
}

