<?php

namespace App\Services;

use App\DTO\ScanConfig;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Random\RandomException;

/**
 * Service for crawling websites using BFS traversal.
 *
 * Handles the main crawl loop, queue management, visited tracking,
 * and orchestrates the ScannerService and SitemapService.
 */
class CrawlerService
{
    protected array $visited = [];
    protected array $queue = [];
    protected array $results = [];
    protected ?Client $client = null;

    public function __construct(
        protected ScannerService $scannerService,
        protected SitemapService $sitemapService,
    ) {}

    /**
     * Set a custom HTTP client (primarily for testing).
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Crawl a website based on the provided configuration.
     *
     * @param ScanConfig $config The scan configuration.
     * @param Closure|null $onProgress Progress callback: fn(int $scanned, int $total): void
     * @param Closure|null $onSitemapDiscovery Sitemap discovery callback: fn(string $message): void
     * @return array The scan results.
     * @throws RandomException|GuzzleException
     */
    public function crawl(ScanConfig $config, ?Closure $onProgress = null, ?Closure $onSitemapDiscovery = null): array
    {
        // Reset state for new crawl
        $this->visited = [];
        $this->queue = [];
        $this->results = [];

        // Configure HTTP client (use injected client or create new one)
        $client = $this->client ?? $this->createHttpClient($config->timeout);

        // Configure scanner service
        $this->scannerService->setClient($client);
        $this->scannerService->setBaseUrl($config->baseUrl);

        if (!empty($config->customTrackingParams)) {
            $this->scannerService->addTrackingParams($config->customTrackingParams);
        }

        // Configure sitemap service
        $this->sitemapService->setClient($client);

        // Initialize queue with starting URL
        $this->queue[] = [
            'url' => $config->baseUrl,
            'depth' => 0,
            'source' => 'start',
            'element' => 'a',
        ];

        // Discover URLs from sitemap if enabled
        if ($config->useSitemap) {
            $this->discoverFromSitemap($config->baseUrl, $onSitemapDiscovery);
        }

        $scannedCount = 0;

        while (!empty($this->queue) && $scannedCount < $config->maxUrls) {
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
            if ($depth > $config->maxDepth) {
                continue;
            }

            $this->visited[$url] = true;
            $scannedCount++;

            $isInternal = $this->scannerService->isInternalUrl($url);

            if ($isInternal) {
                $this->processInternalUrl($url, $depth, $source, $element, $config->scanElements);
            } else {
                $this->processExternalUrl($url, $source, $element, $config->scanElements);
            }

            // Rate limiting
            usleep(random_int($config->delayMin * 1000, $config->delayMax * 1000));

            // Progress callback
            if ($onProgress !== null) {
                $onProgress($scannedCount, $config->maxUrls);
            }
        }

        return $this->results;
    }

    /**
     * Create an HTTP client with the specified timeout.
     */
    protected function createHttpClient(int $timeout): Client
    {
        return new Client([
            'timeout' => $timeout,
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
    }

    /**
     * Discover URLs from sitemap and add to queue.
     */
    protected function discoverFromSitemap(string $baseUrl, ?Closure $onMessage = null): void
    {
        if ($onMessage !== null) {
            $onMessage('Discovering URLs from sitemap...');
        }

        $result = $this->sitemapService->discoverUrls($baseUrl);

        if ($result['count'] > 0) {
            foreach ($result['urls'] as $urlData) {
                if (!isset($this->visited[$urlData['url']])) {
                    $this->queue[] = [
                        'url' => $urlData['url'],
                        'depth' => 0,
                        'source' => $urlData['source'],
                        'element' => 'a',
                    ];
                }
            }

            if ($onMessage !== null) {
                $onMessage("  Found {$result['count']} URLs from sitemap (will also crawl links from pages)");
            }
        } else {
            if ($onMessage !== null) {
                $onMessage('  No sitemap found, using page crawling only');
            }
        }
    }

    /**
     * Process an internal URL.
     *
     * @param array<string> $scanElements Elements to scan
     * @throws GuzzleException
     */
    protected function processInternalUrl(string $url, int $depth, string $source, string $element, array $scanElements): void
    {
        $shouldStoreResult = in_array($element, $scanElements);

        // For non-<a> internal elements, skip entirely if not in scanElements
        if ($element !== 'a' && !$shouldStoreResult) {
            return;
        }

        $result = $this->scannerService->processInternalUrl($url, $source, $element);

        // Extract links and remove from result
        $extractedLinks = $result['extractedLinks'] ?? [];
        unset($result['extractedLinks']);

        // Only store result if element type is in scanElements
        if ($shouldStoreResult) {
            $this->results[] = $result;
        }

        // Add extracted links to the queue
        foreach ($extractedLinks as $link) {
            $linkElement = $link['element'] ?? 'a';

            if (!isset($this->visited[$link['url']])) {
                $queueItem = [
                    'url' => $link['url'],
                    'depth' => $depth + 1,
                    'source' => $url,
                    'element' => $linkElement,
                ];

                // Prioritize scanElements (except 'a') by adding to front
                if (in_array($linkElement, $scanElements) && $linkElement !== 'a') {
                    array_unshift($this->queue, $queueItem);
                } else {
                    $this->queue[] = $queueItem;
                }
            }
        }
    }

    /**
     * Process an external URL.
     *
     * @param array<string> $scanElements Elements to scan
     * @throws GuzzleException
     */
    protected function processExternalUrl(string $url, string $source, string $element, array $scanElements): void
    {
        // Skip if element type is not in scanElements
        if (!in_array($element, $scanElements)) {
            return;
        }

        $result = $this->scannerService->processExternalUrl($url, $source, $element);
        $this->results[] = $result;
    }

    /**
     * Get the current results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get the visited URLs.
     */
    public function getVisited(): array
    {
        return $this->visited;
    }
}

