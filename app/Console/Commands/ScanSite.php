<?php

namespace App\Console\Commands;

use App\Services\ScannerService;
use App\Services\SitemapService;
use GuzzleHttp\Client;
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
        {--sitemap : Use sitemap.xml to discover URLs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan a website for broken links using BFS crawling';

    protected Client $client;
    protected ScannerService $scannerService;
    protected SitemapService $sitemapService;
    protected array $visited = [];
    protected array $queue = [];
    protected array $results = [];
    protected string $baseHost;
    protected string $baseUrl;
    protected array $scanElements = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->baseUrl = rtrim($this->argument('url'), '/');
        $parsedUrl = parse_url($this->baseUrl);

        if (!isset($parsedUrl['host'])) {
            $this->error('Invalid URL provided.');
            return CommandAlias::FAILURE;
        }

        $this->baseHost = $parsedUrl['host'];

        $this->client = new Client([
            'timeout' => (int) $this->option('timeout'),
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
            ],
        ]);

        // Initialize the scanner service
        $this->scannerService = app(ScannerService::class);
        $this->scannerService->setClient($this->client);
        $this->scannerService->setBaseUrl($this->baseUrl);

        // Initialize the sitemap service with an HTTP client
        $this->sitemapService = app(SitemapService::class);
        $this->sitemapService->setClient($this->client);

        $maxDepth = (int) $this->option('depth');
        $maxUrls = (int) $this->option('max');

        // Parse scan-elements option
        $scanElementsOption = $this->option('scan-elements');
        if ($scanElementsOption === 'all') {
            $this->scanElements = ['a', 'link', 'script', 'img'];
        } else {
            $this->scanElements = array_map('trim', explode(',', $scanElementsOption));
        }

        $this->info("Site Scan: {$this->baseUrl}");
        $this->info(str_repeat('=', 40));
        $this->newLine();

        // Initialize BFS queue with starting URL
        $this->queue[] = ['url' => $this->baseUrl, 'depth' => 0, 'source' => 'start', 'element' => 'a'];

        // If --sitemap option is used, discover URLs from sitemap.xml
        if ($this->option('sitemap')) {
            $this->discoverFromSitemap();
        }

        $scannedCount = 0;
        $progressBar = $this->output->createProgressBar($maxUrls);
        $progressBar->start();

        while (!empty($this->queue) && $scannedCount < $maxUrls) {
            $current = array_shift($this->queue);
            $url = $current['url'];
            $depth = $current['depth'];
            $source = $current['source'];
            $element = $current['element'] ?? 'a';

            // Skip if already visited
            if (isset($this->visited[$url])) {
                continue;
            }

            // Skip if beyond max depth
            if ($depth > $maxDepth) {
                continue;
            }

            $this->visited[$url] = true;
            $scannedCount++;

            $isInternal = $this->scannerService->isInternalUrl($url);

            if ($isInternal) {
                $this->processInternalUrl($url, $depth, $source, $element);
            } else {
                $this->processExternalUrl($url, $source, $element);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults();

        return CommandAlias::SUCCESS;
    }

    protected function processInternalUrl(string $url, int $depth, string $source, string $element): void
    {
        // For internal <a> links, we always need to fetch the page to discover content
        // But we only store the result if the element is in scanElements
        $shouldStoreResult = in_array($element, $this->scanElements);

        // For non-<a> internal elements (img, script, link), skip entirely if not in scanElements
        if ($element !== 'a' && !$shouldStoreResult) {
            return;
        }

        $result = $this->scannerService->processInternalUrl($url, $source, $element);

        // Store result without extractedLinks
        $extractedLinks = $result['extractedLinks'] ?? [];
        unset($result['extractedLinks']);

        // Only store result if element type is in scanElements list
        if ($shouldStoreResult) {
            $this->results[] = $result;
        }

        // Add extracted links to the queue
        foreach ($extractedLinks as $link) {
            $linkElement = $link['element'] ?? 'a';

            if (!isset($this->visited[$link['url']])) {
                $this->queue[] = [
                    'url' => $link['url'],
                    'depth' => $depth + 1,
                    'source' => $url,
                    'element' => $linkElement,
                ];
            }
        }
    }

    protected function processExternalUrl(string $url, string $source, string $element): void
    {
        // Skip external URLs if element type is not in scanElements list
        if (!in_array($element, $this->scanElements)) {
            return;
        }

        $result = $this->scannerService->processExternalUrl($url, $source, $element);
        $this->results[] = $result;
    }

    protected function discoverFromSitemap(): void
    {
        $this->info('Discovering URLs from sitemap...');

        $result = $this->sitemapService->discoverUrls($this->baseUrl);

        if ($result['count'] > 0) {
            // Add discovered URLs to the queue
            foreach ($result['urls'] as $urlData) {
                if (!isset($this->visited[$urlData['url']])) {
                    $this->queue[] = [
                        'url' => $urlData['url'],
                        'depth' => 0, // Treat as entry point so it crawls links from these pages too
                        'source' => $urlData['source'],
                    ];
                }
            }
            $this->info("  Found {$result['count']} URLs from sitemap (will also crawl links from pages)");
        } else {
            $this->warn('  No sitemap found, using page crawling only');
        }
        $this->newLine();
    }


    protected function displayResults(): void
    {
        $format = $this->option('format');
        $statusFilter = $this->option('status');
        $elementFilter = $this->option('filter');

        // Filter results using the scanner service
        $filtered = $this->scannerService->filterResults($this->results, $statusFilter);
        $filtered = $this->scannerService->filterByElement($filtered, $elementFilter);

        // Calculate stats using the scanner service
        $stats = $this->scannerService->calculateStats($this->results);

        // Display based on format
        match ($format) {
            'json' => $this->displayJson($filtered, $stats),
            'csv' => $this->displayCsv($filtered),
            default => $this->displayTable($filtered, $stats),
        };
    }


    protected function displayTable(array $results, array $stats): void
    {
        $this->info('Summary:');
        $this->line("  Total links:    {$stats['total']}");
        $this->line("  Working (2xx):  {$stats['ok']}");
        $this->line("  Redirects:      {$stats['redirects']}");
        $this->line("  Broken:         {$stats['broken']}");
        $this->line("  Timeouts:       {$stats['timeouts']}");
        $this->newLine();

        // Redirect chain summary (always shown when > 0)
        if ($stats['redirectChainCount'] > 0) {
            $this->line("  ⚠ Redirect chains: {$stats['redirectChainCount']} chains, {$stats['totalRedirectHops']} total hops");
        }

        // HTTPS downgrade warning (only shown when > 0)
        if ($stats['httpsDowngrades'] > 0) {
            $this->warn("  ⚠ HTTPS downgrades: {$stats['httpsDowngrades']}");

            // List affected URLs when verbose
            if ($this->output->isVerbose()) {
                $downgradedUrls = array_filter($this->results, fn($r) => $r['hasHttpsDowngrade'] ?? false);
                foreach ($downgradedUrls as $result) {
                    $this->line("    - {$result['url']}");
                }
            }
        }

        if ($stats['redirectChainCount'] > 0 || $stats['httpsDowngrades'] > 0) {
            $this->newLine();
        }

        if (empty($results)) {
            $this->info('No links to display for the selected filter.');
            return;
        }

        $tableData = [];
        foreach ($results as $result) {
            $row = [
                'URL' => $this->truncate($result['url'], 50),
                'Source' => $this->truncate($result['sourcePage'], 30),
                'Element' => '<' . ($result['sourceElement'] ?? 'a') . '>',
                'Status' => $result['status'],
                'Type' => $result['type'],
            ];

            // Add redirect chain if verbose
            if ($this->output->isVerbose() && !empty($result['redirectChain'])) {
                $row['Redirects'] = implode(' → ', array_map(fn($u) => $this->truncate($u, 30), $result['redirectChain']));
            }

            $tableData[] = $row;
        }

        $headers = ['URL', 'Source', 'Element', 'Status', 'Type'];
        if ($this->output->isVerbose()) {
            $headers[] = 'Redirects';
        }

        $this->table($headers, $tableData);
    }

    protected function displayJson(array $results, array $stats): void
    {
        $output = [
            'summary' => $stats,
            'results' => array_values($results),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function displayCsv(array $results): void
    {
        // Header
        $this->line('URL,Source,Element,Status,Type,Redirects,IsOk,HttpsDowngrade');

        foreach ($results as $result) {
            $redirects = implode(' -> ', $result['redirectChain']);
            $isOk = $result['isOk'] ? 'true' : 'false';
            $httpsDowngrade = ($result['hasHttpsDowngrade'] ?? false) ? 'true' : 'false';
            $element = $result['sourceElement'] ?? 'a';

            $this->line(sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $result['url']),
                str_replace('"', '""', $result['sourcePage']),
                $element,
                $result['status'],
                $result['type'],
                str_replace('"', '""', $redirects),
                $isOk,
                $httpsDowngrade
            ));
        }
    }

    protected function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }
}
