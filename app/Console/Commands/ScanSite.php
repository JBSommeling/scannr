<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\DomCrawler\Crawler;

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
        {--sitemap : Use sitemap.xml to discover URLs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan a website for broken links using BFS crawling';

    protected Client $client;
    protected array $visited = [];
    protected array $queue = [];
    protected array $results = [];
    protected string $baseHost;
    protected string $baseUrl;
    protected int $maxRedirects = 5;

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

        $maxDepth = (int) $this->option('depth');
        $maxUrls = (int) $this->option('max');

        $this->info("Site Scan: {$this->baseUrl}");
        $this->info(str_repeat('=', 40));
        $this->newLine();

        // Initialize BFS queue with starting URL
        $this->queue[] = ['url' => $this->baseUrl, 'depth' => 0, 'source' => 'start'];

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

            $isInternal = $this->isInternalUrl($url);

            if ($isInternal) {
                $this->processInternalUrl($url, $depth, $source);
            } else {
                $this->processExternalUrl($url, $source);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults();

        return CommandAlias::SUCCESS;
    }

    protected function processInternalUrl(string $url, int $depth, string $source): void
    {
        $result = $this->followRedirects($url, 'GET');

        $this->results[] = [
            'url' => $url,
            'sourcePage' => $source,
            'status' => $result['finalStatus'],
            'type' => 'internal',
            'redirectChain' => $result['chain'],
            'isOk' => $result['finalStatus'] >= 200 && $result['finalStatus'] < 300,
            'isLoop' => $result['loop'],
            'hasHttpsDowngrade' => $result['hasHttpsDowngrade'],
        ];

        // If successful and got HTML content, parse for more links
        if ($result['finalStatus'] === 200 && $result['body'] !== null) {
            $this->extractLinks($result['body'], $url, $depth);
        }
    }

    protected function processExternalUrl(string $url, string $source): void
    {
        $result = $this->followRedirects($url, 'HEAD');

        $this->results[] = [
            'url' => $url,
            'sourcePage' => $source,
            'status' => $result['finalStatus'],
            'type' => 'external',
            'redirectChain' => $result['chain'],
            'isOk' => $result['finalStatus'] >= 200 && $result['finalStatus'] < 300,
            'isLoop' => $result['loop'],
            'hasHttpsDowngrade' => $result['hasHttpsDowngrade'],
        ];
    }

    protected function followRedirects(string $url, string $method = 'GET'): array
    {
        $chain = [];
        $currentUrl = $url;
        $hops = 0;
        $body = null;
        $finalStatus = 0;
        $loop = false;
        $hasHttpsDowngrade = false;

        while ($hops < $this->maxRedirects) {
            try {
                $response = $this->client->request($method, $currentUrl);
                $finalStatus = $response->getStatusCode();

                // If 3xx redirect
                if ($finalStatus >= 300 && $finalStatus < 400) {
                    $location = $response->getHeaderLine('Location');

                    if (empty($location)) {
                        break;
                    }

                    // Normalize redirect location
                    $location = $this->normalizeUrl($location, $currentUrl);

                    // Check for HTTPS to HTTP downgrade
                    $currentScheme = parse_url($currentUrl, PHP_URL_SCHEME);
                    $locationScheme = parse_url($location, PHP_URL_SCHEME);
                    if ($currentScheme === 'https' && $locationScheme === 'http') {
                        $hasHttpsDowngrade = true;
                    }

                    // Check for loop
                    if (in_array($location, $chain) || $location === $url) {
                        $loop = true;
                        $chain[] = $location . ' (LOOP)';
                        break;
                    }

                    $chain[] = $location;
                    $currentUrl = $location;
                    $hops++;
                    continue;
                }

                // Got final response (200, 404, 5xx, etc.)
                if ($method === 'GET' && $finalStatus === 200) {
                    $body = (string) $response->getBody();
                }
                break;

            } catch (ConnectException $e) {
                $finalStatus = 'Timeout';
                break;
            } catch (RequestException $e) {
                $finalStatus = $e->hasResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 'Error';
                break;
            } catch (\Exception $e) {
                $finalStatus = 'Error';
                break;
            }
        }

        return [
            'finalStatus' => $finalStatus,
            'chain' => $chain,
            'loop' => $loop,
            'body' => $body,
            'hasHttpsDowngrade' => $hasHttpsDowngrade,
        ];
    }

    protected function extractLinks(string $html, string $sourceUrl, int $currentDepth): void
    {
        try {
            $crawler = new Crawler($html, $sourceUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use ($sourceUrl, $currentDepth) {
                $href = $node->attr('href');

                if ($href === null || $href === '') {
                    return;
                }

                // Skip javascript:, mailto:, tel:, etc.
                if (preg_match('/^(javascript|mailto|tel|#)/', $href)) {
                    return;
                }

                $normalizedUrl = $this->normalizeUrl($href, $sourceUrl);

                if ($normalizedUrl === null) {
                    return;
                }

                // Skip if already visited or in queue
                if (isset($this->visited[$normalizedUrl])) {
                    return;
                }

                // Add to queue
                $this->queue[] = [
                    'url' => $normalizedUrl,
                    'depth' => $currentDepth + 1,
                    'source' => $sourceUrl,
                ];
            });
        } catch (\Exception $e) {
            // Silently handle parsing errors
        }
    }

    protected function normalizeUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Remove fragment
        $url = preg_replace('/#.*$/', '', $url);

        if ($url === '') {
            return null;
        }

        // Handle protocol-relative URLs
        if (str_starts_with($url, '//')) {
            $parsedBase = parse_url($baseUrl);
            $url = ($parsedBase['scheme'] ?? 'https') . ':' . $url;
        }

        // Handle absolute URLs
        if (preg_match('/^https?:\/\//', $url)) {
            return rtrim($url, '/');
        }

        // Handle relative URLs
        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($url, '/')) {
            // Absolute path
            return rtrim("{$scheme}://{$host}{$port}{$url}", '/');
        }

        // Relative path
        $basePath = $parsedBase['path'] ?? '/';
        $basePath = preg_replace('/\/[^\/]*$/', '/', $basePath);

        return rtrim("{$scheme}://{$host}{$port}{$basePath}{$url}", '/');
    }

    protected function isInternalUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            return true;
        }

        return $parsed['host'] === $this->baseHost
            || str_ends_with($parsed['host'], '.' . $this->baseHost);
    }

    protected function discoverFromSitemap(): void
    {
        $sitemapUrls = [
            $this->baseUrl . '/sitemap.xml',
            $this->baseUrl . '/sitemap_index.xml',
            $this->baseUrl . '/sitemap/',
        ];

        // First try to get sitemap URL from robots.txt
        $robotsUrl = $this->baseUrl . '/robots.txt';
        try {
            $response = $this->client->request('GET', $robotsUrl);
            if ($response->getStatusCode() === 200) {
                $robotsContent = (string) $response->getBody();
                if (preg_match_all('/Sitemap:\s*(.+)/i', $robotsContent, $matches)) {
                    $sitemapUrls = array_merge($matches[1], $sitemapUrls);
                }
            }
        } catch (\Exception $e) {
            // Ignore robots.txt errors
        }

        $this->info('Discovering URLs from sitemap...');
        $discoveredCount = 0;

        foreach ($sitemapUrls as $sitemapUrl) {
            $sitemapUrl = trim($sitemapUrl);
            $discoveredCount += $this->parseSitemap($sitemapUrl);

            if ($discoveredCount > 0) {
                break; // Found a working sitemap
            }
        }

        if ($discoveredCount > 0) {
            $this->info("  Found {$discoveredCount} URLs from sitemap (will also crawl links from pages)");
        } else {
            $this->warn('  No sitemap found, using page crawling only');
        }
        $this->newLine();
    }

    protected function parseSitemap(string $url, int $depth = 0): int
    {
        if ($depth > 3) {
            return 0; // Prevent infinite recursion in sitemap indexes
        }

        try {
            $response = $this->client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return 0;
            }

            $content = (string) $response->getBody();
            $count = 0;

            // Suppress XML errors
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            libxml_clear_errors();

            if ($xml === false) {
                return 0;
            }

            // Register namespaces
            $namespaces = $xml->getNamespaces(true);
            if (isset($namespaces[''])) {
                $xml->registerXPathNamespace('sm', $namespaces['']);
            }

            // Check if it's a sitemap index
            $sitemapNodes = $xml->xpath('//sm:sitemap/sm:loc') ?: $xml->xpath('//sitemap/loc');
            if (!empty($sitemapNodes)) {
                foreach ($sitemapNodes as $node) {
                    $childSitemapUrl = (string) $node;
                    $count += $this->parseSitemap($childSitemapUrl, $depth + 1);
                }
                return $count;
            }

            // Parse regular sitemap URLs
            $urlNodes = $xml->xpath('//sm:url/sm:loc') ?: $xml->xpath('//url/loc');
            if (!empty($urlNodes)) {
                foreach ($urlNodes as $node) {
                    $pageUrl = (string) $node;
                    $pageUrl = rtrim($pageUrl, '/');

                    if (!isset($this->visited[$pageUrl]) && $this->isInternalUrl($pageUrl)) {
                        $this->queue[] = [
                            'url' => $pageUrl,
                            'depth' => 0, // Treat as entry point so it crawls links from these pages too
                            'source' => 'sitemap',
                        ];
                        $count++;
                    }
                }
            }

            return $count;

        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function displayResults(): void
    {
        $format = $this->option('format');
        $statusFilter = $this->option('status');

        // Filter results
        $filtered = $this->filterResults($statusFilter);

        // Calculate stats
        $stats = $this->calculateStats();

        // Display based on format
        match ($format) {
            'json' => $this->displayJson($filtered, $stats),
            'csv' => $this->displayCsv($filtered),
            default => $this->displayTable($filtered, $stats),
        };
    }

    protected function filterResults(string $filter): array
    {
        return match ($filter) {
            'ok' => array_filter($this->results, fn($r) => $r['isOk']),
            'broken' => array_filter($this->results, fn($r) => !$r['isOk']),
            default => $this->results,
        };
    }

    protected function calculateStats(): array
    {
        $total = count($this->results);
        $ok = count(array_filter($this->results, fn($r) => $r['isOk'] && empty($r['redirectChain'])));
        $redirects = count(array_filter($this->results, fn($r) => !empty($r['redirectChain']) && $r['isOk']));
        $broken = count(array_filter($this->results, fn($r) => !$r['isOk'] && $r['status'] !== 'Timeout'));
        $timeouts = count(array_filter($this->results, fn($r) => $r['status'] === 'Timeout'));

        // Redirect chain statistics
        $redirectChainCount = count(array_filter($this->results, fn($r) => !empty($r['redirectChain'])));
        $totalRedirectHops = array_sum(array_map(fn($r) => count($r['redirectChain']), $this->results));

        // HTTPS downgrade count
        $httpsDowngrades = count(array_filter($this->results, fn($r) => $r['hasHttpsDowngrade'] ?? false));

        return [
            'total' => $total,
            'ok' => $ok,
            'redirects' => $redirects,
            'broken' => $broken,
            'timeouts' => $timeouts,
            'redirectChainCount' => $redirectChainCount,
            'totalRedirectHops' => $totalRedirectHops,
            'httpsDowngrades' => $httpsDowngrades,
        ];
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
                'Status' => $result['status'],
                'Type' => $result['type'],
            ];

            // Add redirect chain if verbose
            if ($this->output->isVerbose() && !empty($result['redirectChain'])) {
                $row['Redirects'] = implode(' → ', array_map(fn($u) => $this->truncate($u, 30), $result['redirectChain']));
            }

            $tableData[] = $row;
        }

        $headers = ['URL', 'Source', 'Status', 'Type'];
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
        $this->line('URL,Source,Status,Type,Redirects,IsOk,HttpsDowngrade');

        foreach ($results as $result) {
            $redirects = implode(' -> ', $result['redirectChain']);
            $isOk = $result['isOk'] ? 'true' : 'false';
            $httpsDowngrade = ($result['hasHttpsDowngrade'] ?? false) ? 'true' : 'false';

            $this->line(sprintf(
                '"%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $result['url']),
                str_replace('"', '""', $result['sourcePage']),
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
