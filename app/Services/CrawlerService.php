<?php

namespace App\Services;

use App\DTO\ScanConfig;
use App\Services\BrowsershotFetcher;
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
    protected ?string $originalHost = null;
    protected ?string $canonicalHost = null;

    public function __construct(
        protected ScannerService $scannerService,
        protected SitemapService $sitemapService,
        protected RobotsService $robotsService = new RobotsService(),
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
        $this->originalHost = parse_url($config->baseUrl, PHP_URL_HOST);
        $this->canonicalHost = null;
        $canonicalBaseResolved = false;

        // Configure HTTP client (use injected client or create new one)
        $client = $this->client ?? $this->createHttpClient($config->timeout);

        // Configure scanner service
        $this->scannerService->setClient($client);
        $this->scannerService->setBaseUrl($config->baseUrl);

        // Configure JavaScript rendering if enabled
        if ($config->useJsRendering) {
            $depCheck = BrowsershotFetcher::checkDependencies();
            if ($depCheck['available']) {
                $fetcher = new BrowsershotFetcher();
                $fetcher->setTimeout($config->timeout);

                // Load custom paths from config if available
                $jsConfig = config('scanner.js_rendering', []);
                if (!empty($jsConfig)) {
                    $fetcher->configure($jsConfig);
                }

                $this->scannerService->setBrowsershotFetcher($fetcher);

                if ($onSitemapDiscovery !== null) {
                    $onSitemapDiscovery('  JavaScript rendering enabled (headless browser)');
                }
            } else {
                if ($onSitemapDiscovery !== null) {
                    $onSitemapDiscovery("  Warning: {$depCheck['message']} Falling back to static HTML.");
                }
            }
        }

        if (!empty($config->customTrackingParams)) {
            $this->scannerService->addTrackingParams($config->customTrackingParams);
        }

        // Configure sitemap service
        $this->sitemapService->setClient($client);

        // Fetch and parse robots.txt if respect-robots is enabled
        $delayMin = $config->delayMin;
        $delayMax = $config->delayMax;

        if ($config->respectRobots) {
            $this->robotsService->setClient($client);
            $this->robotsService->fetchAndParse($config->baseUrl);

            $robotsDelay = $this->robotsService->getCrawlDelay();
            if ($robotsDelay !== null) {
                $robotsDelayMs = (int) ($robotsDelay * 1000);
                $delayMin = max($delayMin, $robotsDelayMs);
                $delayMax = max($delayMax, $robotsDelayMs);

                if ($onSitemapDiscovery !== null) {
                    $onSitemapDiscovery("  Robots.txt Crawl-delay: {$robotsDelay}s (using {$delayMin}ms-{$delayMax}ms delay)");
                }
            }

            $rulesCount = count($this->robotsService->getRules());
            if ($rulesCount > 0 && $onSitemapDiscovery !== null) {
                $onSitemapDiscovery("  Robots.txt: respecting {$rulesCount} Disallow/Allow rule(s)");
            }
        }

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

            // Skip if disallowed by robots.txt (only for internal URLs)
            if ($config->respectRobots && $this->scannerService->isInternalUrl($url) && !$this->robotsService->isAllowed($url)) {
                $this->visited[$url] = true;
                continue;
            }

            $this->visited[$url] = true;
            $scannedCount++;

            $isInternal = $this->scannerService->isInternalUrl($url);

            if ($isInternal) {
                $this->processInternalUrl($url, $depth, $source, $element, $config->scanElements);

                // After processing the first URL (start), check if there was a redirect
                // and update the base URL to the canonical URL to avoid counting
                // the same redirect (e.g., www -> non-www) for every resource
                if (!$canonicalBaseResolved && $source === 'start' && !empty($this->results)) {
                    $firstResult = end($this->results);
                    $finalUrl = rtrim($firstResult['finalUrl'] ?? $url, '/');
                    $finalHost = parse_url($finalUrl, PHP_URL_HOST);

                    // Check if the final URL has a different host than the original
                    // This catches www-only redirects that aren't in the redirectChain
                    if ($finalHost !== null && $finalHost !== $this->originalHost) {
                        $this->canonicalHost = $finalHost;
                        $this->scannerService->setBaseUrl($finalUrl);

                        // Clear the redirect chain for the start URL since it's expected
                        if (!empty($firstResult['redirectChain'])) {
                            $this->results[array_key_last($this->results)]['redirectChain'] = [];
                        }

                        // Mark the canonical URL as visited to prevent duplicates
                        // when sitemap contains the canonical URL (e.g., non-www)
                        // while we started with the original URL (e.g., www)
                        $this->visited[$finalUrl] = true;

                        // Rewrite all URLs currently in the queue to use canonical host
                        $this->rewriteQueueToCanonicalHost();
                    }
                    $canonicalBaseResolved = true;
                }
            } else {
                $this->processExternalUrl($url, $source, $element, $config->scanElements);
            }

            // Rate limiting (respects Crawl-delay from robots.txt)
            usleep(random_int($delayMin * 1000, $delayMax * 1000));

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
        $defaultUserAgent = 'ScannrBot/1.0 (+https://scannr.io)';
        try {
            $userAgent = config('scanner.user_agent', $defaultUserAgent) ?? $defaultUserAgent;
        } catch (\Throwable) {
            $userAgent = $defaultUserAgent;
        }

        return new Client([
            'timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
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
            // Rewrite URL to canonical host if needed (e.g., www -> non-www)
            $linkUrl = $this->rewriteUrlToCanonicalHost($link['url']);

            if (!isset($this->visited[$linkUrl])) {
                $queueItem = [
                    'url' => $linkUrl,
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

    /**
     * Rewrite a URL from the original host to the canonical host.
     *
     * This handles cases where www.example.com redirects to example.com,
     * ensuring we use the canonical URL to avoid duplicate redirect chains.
     */
    protected function rewriteUrlToCanonicalHost(string $url): string
    {
        if ($this->canonicalHost === null || $this->originalHost === null) {
            return $url;
        }

        if ($this->canonicalHost === $this->originalHost) {
            return $url;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost === $this->originalHost) {
            return str_replace("//{$this->originalHost}", "//{$this->canonicalHost}", $url);
        }

        return $url;
    }

    /**
     * Rewrite all URLs in the queue to use the canonical host.
     *
     * Called after discovering the canonical host to ensure all queued
     * URLs use the correct host. Also removes URLs that are already visited
     * (which can happen when sitemap URLs match the canonical URL of the start page).
     */
    protected function rewriteQueueToCanonicalHost(): void
    {
        $filteredQueue = [];
        foreach ($this->queue as $item) {
            $item['url'] = $this->rewriteUrlToCanonicalHost($item['url']);
            // Skip if this URL is already visited (e.g., sitemap homepage matching canonical start URL)
            if (!isset($this->visited[$item['url']])) {
                $filteredQueue[] = $item;
            }
        }
        $this->queue = $filteredQueue;
    }
}

