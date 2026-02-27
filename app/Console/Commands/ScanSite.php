<?php

namespace App\Console\Commands;

use App\DTO\ScanConfig;
use App\Services\CrawlerService;
use App\Services\Output\ConsoleOutput;
use App\Services\ResultFormatterService;
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
        {--filter=all : Filter displayed results by element type (all, a, link, script, img)}
        {--scan-elements=all : Element types to scan (all, or comma-separated: a,img,link,script)}
        {--sitemap : Use sitemap.xml to discover URLs}
        {--strip-params= : Additional tracking parameters to strip (comma-separated, e.g., ref,tracker_*)}
        {--js : Enable JavaScript rendering for SPA/React sites (requires Node.js + Puppeteer)}
        {--no-robots : Ignore robots.txt rules (Disallow/Crawl-delay)}';


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

        if (!isset($parsedUrl['host'])) {
            $this->error('Invalid URL provided.');
            return CommandAlias::FAILURE;
        }

        // Create configuration from command options
        $result = ScanConfig::fromCommandOptions($this);
        $config = $result['config'];

        // Display any warnings
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        // Display scan info
        $this->info("Site Scan: {$config->baseUrl}");
        $this->info(str_repeat('=', 40));
        $this->newLine();

        // Create progress bar
        $progressBar = $this->output->createProgressBar($config->maxUrls);
        $progressBar->start();

        // Create output adapter
        $output = new ConsoleOutput($this);

        // Run the crawl
        $results = $this->crawlerService->crawl(
            $config,
            fn(int $scanned, int $total) => $progressBar->setProgress($scanned),
            fn(string $message) => $this->info($message),
        );

        $progressBar->finish();
        $this->newLine(2);

        // Format and display results
        $this->resultFormatter->format($results, $config, $output);

        return CommandAlias::SUCCESS;
    }
}
