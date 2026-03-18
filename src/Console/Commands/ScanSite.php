<?php

namespace Scannr\Console\Commands;

use Scannr\DTO\ScanConfig;
use Scannr\Jobs\ScanSiteJob;
use Scannr\Models\ScanResult;
use Scannr\Services\CrawlerService;
use Scannr\Services\Output\ConsoleOutput;
use Scannr\Services\ResultFormatterService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ScanSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:scan
        {url : The URL to scan}
        {--depth=3 : Maximum crawl depth}
        {--max=300 : Maximum number of URLs to scan}
        {--timeout=5 : Request timeout in seconds}
        {--format=table : Output format (table, json, csv)}
        {--status=all : Filter results (all, ok, broken)}
        {--filter=all : Filter displayed results by element type (all, a, link, script, img, media, form)}
        {--scan-elements=all : Element types to scan (all, or comma-separated: a,img,link,script,media,form)}
        {--sitemap : Use sitemap.xml to discover URLs}
        {--strip-params= : Additional tracking parameters to strip (comma-separated, e.g., ref,tracker_*)}
        {--js : Enable JavaScript rendering for SPA/React sites (requires Node.js + Puppeteer)}
        {--smart-js : Automatically enable JS rendering when SPA signals are detected (empty DOM, no links, client-side routing)}
        {--delay-min= : Minimum delay between requests in milliseconds (overrides config)}
        {--delay-max= : Maximum delay between requests in milliseconds (overrides config)}
        {--no-robots : Ignore robots.txt rules (Disallow/Crawl-delay)}
        {--advanced : Show XML namespaces, CDN root domains, and JS framework links}
        {--queue : Dispatch scan as a background job}
        {--fail-on-critical : Fail with exit code 1 if critical issues are found}
        {--min-rating=none : Minimum acceptable rating (excellent, good, needs_attention, none)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan a website for broken links using BFS crawling';

    public function __construct(
        protected CrawlerService $crawlerService,
        protected ResultFormatterService $resultFormatter,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Validate URL
        $url = $this->argument('url');
        $parsedUrl = parse_url($url);

        if (! isset($parsedUrl['host'])) {
            $this->error('Invalid URL provided.');

            return CommandAlias::FAILURE;
        }

        // Domain validation: warn if scanned URL doesn't match APP_URL
        $this->validateDomain($parsedUrl['host']);

        // Create configuration from command options
        $result = ScanConfig::fromCommandOptions($this);
        $config = $result['config'];

        // Display any warnings
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        // Dispatch as a background job if --queue is set
        if ($this->option('queue')) {
            return $this->dispatchJob($config);
        }

        return $this->runSynchronously($config);
    }

    /**
     * Validate that the scan URL domain matches the application's configured domain.
     */
    protected function validateDomain(string $scanHost): void
    {
        $appUrl = config('app.url');

        if (! $appUrl) {
            return;
        }

        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (! $appHost) {
            return;
        }

        // Normalize: strip www. prefix for comparison
        $normalizedScanHost = preg_replace('/^www\./', '', strtolower($scanHost));
        $normalizedAppHost = preg_replace('/^www\./', '', strtolower($appHost));

        if ($normalizedScanHost !== $normalizedAppHost) {
            $this->warn("⚠ The scan URL domain ({$scanHost}) does not match your APP_URL ({$appUrl}).");
            $this->warn('  Scannr is intended for scanning your own websites.');
        }
    }

    /**
     * Dispatch the scan as a queued background job.
     */
    protected function dispatchJob(ScanConfig $config): int
    {
        $scanResult = ScanResult::query()->create([
            'url' => $config->baseUrl,
            'config' => $config->toArray(),
            'status' => 'pending',
        ]);

        ScanSiteJob::dispatch($scanResult);

        $this->info("Scan job dispatched for: {$config->baseUrl}");
        $this->info("Scan ID: {$scanResult->id}");
        $this->line('Run a queue worker to process the job.');

        return CommandAlias::SUCCESS;
    }

    /**
     * Run the scan synchronously (default behavior).
     */
    protected function runSynchronously(ScanConfig $config): int
    {

        // Display scan info
        $this->info("Site Scan: {$config->baseUrl}");
        $this->info(str_repeat('=', 40));
        $this->newLine();

        // Create progress bar (lazy start - will be started on first progress callback)
        $progressBar = $this->output->createProgressBar($config->maxUrls);
        $progressBarStarted = false;

        // Create output adapter
        $output = new ConsoleOutput($this);

        // Run the crawl
        $crawlResult = $this->crawlerService->crawl(
            $config,
            function (int $scanned, int $total) use ($progressBar, &$progressBarStarted) {
                if (! $progressBarStarted) {
                    $progressBar->start();
                    $progressBarStarted = true;
                }
                $progressBar->setProgress($scanned);
            },
            fn (string $message) => $this->info($message),
        );

        if ($progressBarStarted) {
            $progressBar->finish();
        }
        $this->newLine(2);

        // Extract results and error from crawl result
        $results = $crawlResult['results'];
        $error = $crawlResult['error'] ?? null;

        // Format and display results, capturing the integrity score
        $scoreResult = $this->resultFormatter->format($results, $config, $output, $error);

        // Return failure if scan was aborted
        if ($crawlResult['aborted'] ?? false) {
            return CommandAlias::FAILURE;
        }

        // Check quality gate: fail on critical issues
        if ($config->failOnCritical && ($scoreResult->summary['criticalIssues'] ?? 0) > 0) {
            $count = $scoreResult->summary['criticalIssues'];
            $this->newLine();
            $this->error("Quality gate failed: {$count} critical issue(s) found.");

            return CommandAlias::FAILURE;
        }

        // Check quality gate: minimum rating threshold
        if ($config->minRating !== 'none') {
            $gradeThresholds = config('scannr.integrity_scoring.grades', [
                'excellent' => 90,
                'good' => 75,
                'needs_attention' => 50,
            ]);

            $requiredScore = $gradeThresholds[$config->minRating] ?? null;

            if ($requiredScore !== null && $scoreResult->overallScore < $requiredScore) {
                $this->newLine();
                $this->error(
                    "Quality gate failed: score {$scoreResult->overallScore} ({$scoreResult->grade}) is below minimum rating '{$config->minRating}' (requires >= {$requiredScore})."
                );

                return CommandAlias::FAILURE;
            }
        }

        return CommandAlias::SUCCESS;
    }
}
