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
    protected \SplQueue $queue;
    protected \SplQueue $priorityQueue;
    protected array $results = [];
    protected ?Client $client = null;
    protected ?string $originalHost = null;
    protected ?string $canonicalHost = null;
    protected bool $canonicalBaseResolved = false;

    /**
     * Total count of 429 responses received during the current crawl.
     */
    protected int $total429Count = 0;

    /**
     * Error message if the crawl was aborted (e.g., due to rate limiting).
     */
    protected ?string $abortError = null;

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
        $this->resetState($config->baseUrl);

        // Configure HTTP client (use injected client or create new one)
        $client = $this->client ?? $this->createHttpClient($config->timeout);

        // Configure scanner service
        $this->scannerService->setClient($client);
        $this->scannerService->setBaseUrl($config->baseUrl);

        $this->configureJsRendering($config, $onSitemapDiscovery);

        if (!empty($config->customTrackingParams)) {
            $this->scannerService->addTrackingParams($config->customTrackingParams);
        }

        // Configure sitemap service
        $this->sitemapService->setClient($client);

        [$delayMin, $delayMax] = $this->configureRobots($config, $client, $onSitemapDiscovery);

        // Initialize queue with starting URL
        $this->initQueue($config, $onSitemapDiscovery);

        // Load rate limit configuration
        $rateLimitConfig = $this->getRateLimitConfig();
        $backoffDelays = $rateLimitConfig['backoff_delays'];
        $respectRetryAfter = $rateLimitConfig['respect_retry_after'];
        $max429BeforeAbort = $rateLimitConfig['max_429_before_abort'];

        $scannedCount = 0;

        while ((!$this->priorityQueue->isEmpty() || !$this->queue->isEmpty()) && $scannedCount < $config->maxUrls) {
            // Check if we should abort due to rate limiting
            if ($max429BeforeAbort > 0 && $this->total429Count >= $max429BeforeAbort) {
                $this->abortError = 'Scan aborted due to rate limiting';
                if ($onSitemapDiscovery !== null) {
                    $onSitemapDiscovery("  ⚠ {$this->abortError} ({$this->total429Count} 429 responses received)");
                }
                break;
            }

            $current = !$this->priorityQueue->isEmpty()
                ? $this->priorityQueue->dequeue()
                : $this->queue->dequeue();
            $url = $current['url'];
            $depth = $current['depth'];
            $source = $current['source'];
            $element = $current['element'] ?? 'a';

            // Skip if already visited (using canonical URL key for deduplication)
            $urlKey = $this->scannerService->canonicalUrlKey($url);
            if (isset($this->visited[$urlKey])) {
                continue;
            }

            // Skip if beyond max depth
            if ($depth > $config->maxDepth) {
                continue;
            }

            // Skip if disallowed by robots.txt (only for internal URLs)
            if ($config->respectRobots && $this->scannerService->isInternalUrl($url) && !$this->robotsService->isAllowed($url)) {
                $this->visited[$urlKey] = true;
                continue;
            }

            $this->visited[$urlKey] = true;
            $scannedCount++;

            $isInternal = $this->scannerService->isInternalUrl($url);

            // Process URL with 429 retry handling
            $result = $this->processUrlWithRetry(
                $url,
                $depth,
                $source,
                $element,
                $config->scanElements,
                $isInternal,
                $backoffDelays,
                $respectRetryAfter,
                $max429BeforeAbort,
                $onSitemapDiscovery,
            );

            // Check if we should abort after processing
            if ($this->abortError !== null) {
                break;
            }

            if ($isInternal && $result !== null) {
                $this->resolveCanonicalBaseFromFirstResult($url, $source);
            }

            // Rate limiting (respects Crawl-delay from robots.txt)
            usleep(random_int($delayMin * 1000, $delayMax * 1000));

            // Progress callback
            if ($onProgress !== null) {
                $onProgress($scannedCount, $config->maxUrls);
            }
        }

        return [
            'results' => $this->results,
            'aborted' => $this->abortError !== null,
            'error' => $this->abortError,
        ];
    }

    /**
     * Get the abort error message if the crawl was aborted.
     */
    public function getAbortError(): ?string
    {
        return $this->abortError;
    }

    /**
     * Reset all crawl state for a new crawl session.
     */
    protected function resetState(string $baseUrl): void
    {
        $this->visited = [];
        $this->queue = new \SplQueue();
        $this->priorityQueue = new \SplQueue();
        $this->results = [];
        $this->originalHost = parse_url($baseUrl, PHP_URL_HOST);
        $this->canonicalHost = null;
        $this->canonicalBaseResolved = false;
        $this->total429Count = 0;
        $this->abortError = null;
    }

    /**
     * Get rate limit configuration from config.
     *
     * @return array{backoff_delays: array<int>, respect_retry_after: bool, max_429_before_abort: int}
     */
    protected function getRateLimitConfig(): array
    {
        $defaults = [
            'backoff_delays' => [2000, 5000, 10000],
            'respect_retry_after' => true,
            'max_429_before_abort' => 5,
        ];

        try {
            $config = config('scanner.rate_limit', $defaults);
            return [
                'backoff_delays' => $config['backoff_delays'] ?? $defaults['backoff_delays'],
                'respect_retry_after' => $config['respect_retry_after'] ?? $defaults['respect_retry_after'],
                'max_429_before_abort' => $config['max_429_before_abort'] ?? $defaults['max_429_before_abort'],
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * Process a URL with 429 retry handling.
     *
     * @param string $url The URL to process.
     * @param int $depth Current crawl depth.
     * @param string $source Source page URL.
     * @param string $element HTML element type.
     * @param array<string> $scanElements Elements to scan.
     * @param bool $isInternal Whether the URL is internal.
     * @param array<int> $backoffDelays Backoff delays in milliseconds.
     * @param bool $respectRetryAfter Whether to respect Retry-After header.
     * @param int $max429BeforeAbort Maximum 429 responses before aborting.
     * @param Closure|null $onMessage Message callback for status updates.
     * @return array|null The result array, or null if skipped/aborted.
     * @throws GuzzleException
     */
    protected function processUrlWithRetry(
        string $url,
        int $depth,
        string $source,
        string $element,
        array $scanElements,
        bool $isInternal,
        array $backoffDelays,
        bool $respectRetryAfter,
        int $max429BeforeAbort,
        ?Closure $onMessage,
    ): ?array {
        $maxRetries = count($backoffDelays);
        $retryCount = 0;

        while (true) {
            // Process the URL
            if ($isInternal) {
                $result = $this->processInternalUrlAndGetResult($url, $depth, $source, $element, $scanElements);
            } else {
                $result = $this->processExternalUrlAndGetResult($url, $source, $element, $scanElements);
            }

            // Check if we got a 429 response
            if ($result !== null && $result['status'] === 429) {
                $this->total429Count++;

                // Check if we should abort (always check before deciding to retry or return)
                if ($max429BeforeAbort > 0 && $this->total429Count >= $max429BeforeAbort) {
                    $this->abortError = 'Scan aborted due to rate limiting';
                    return $result;
                }

                // Check if we have retries left for this URL
                if ($retryCount >= $maxRetries) {
                    // Max retries exhausted for this URL, keep the 429 result
                    return $result;
                }

                // Calculate backoff delay
                $delayMs = $backoffDelays[$retryCount] ?? $backoffDelays[$maxRetries - 1];

                // Check for Retry-After header
                if ($respectRetryAfter && isset($result['retryAfter']) && $result['retryAfter'] > 0) {
                    $delayMs = $result['retryAfter'] * 1000;
                }

                if ($onMessage !== null) {
                    $delaySec = $delayMs / 1000;
                    $onMessage("  ⏳ Rate limited (429) on {$url}, waiting {$delaySec}s before retry...");
                }

                // Wait before retry
                usleep($delayMs * 1000);

                // Remove the 429 result from results array (we'll retry)
                if (!empty($this->results)) {
                    array_pop($this->results);
                }

                $retryCount++;
                continue;
            }

            // Success or other error, we're done with this URL
            return $result;
        }
    }

    /**
     * Process an internal URL and return the result (for retry handling).
     *
     * @param array<string> $scanElements Elements to scan
     * @return array|null The result array, or null if skipped.
     * @throws GuzzleException
     */
    protected function processInternalUrlAndGetResult(string $url, int $depth, string $source, string $element, array $scanElements): ?array
    {
        $shouldStoreResult = in_array($element, $scanElements);

        // For non-<a> internal elements, skip entirely if not in scanElements
        if ($element !== 'a' && !$shouldStoreResult) {
            return null;
        }

        $result = $this->scannerService->processInternalUrl($url, $source, $element);

        // Extract links and remove from result
        $extractedLinks = $result['extractedLinks'] ?? [];
        unset($result['extractedLinks']);

        // Only store result if element type is in scanElements
        if ($shouldStoreResult) {
            $this->results[] = $result;
        }

        // Add extracted links to the queue (only if not rate limited)
        if ($result['status'] !== 429) {
            foreach ($extractedLinks as $link) {
                $linkElement = $link['element'] ?? 'a';
                // Rewrite URL to canonical host if needed (e.g., www -> non-www)
                $linkUrl = $this->rewriteUrlToCanonicalHost($link['url']);

                $linkKey = $this->scannerService->canonicalUrlKey($linkUrl);
                if (!isset($this->visited[$linkKey])) {
                    $queueItem = [
                        'url' => $linkUrl,
                        'depth' => $depth + 1,
                        'source' => $url,
                        'element' => $linkElement,
                    ];

                    // Prioritize scanElements (except 'a') by adding to priority queue
                    if (in_array($linkElement, $scanElements) && $linkElement !== 'a') {
                        $this->priorityQueue->enqueue($queueItem);
                    } else {
                        $this->queue->enqueue($queueItem);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Process an external URL and return the result (for retry handling).
     *
     * @param array<string> $scanElements Elements to scan
     * @return array|null The result array, or null if skipped.
     * @throws GuzzleException
     */
    protected function processExternalUrlAndGetResult(string $url, string $source, string $element, array $scanElements): ?array
    {
        // Skip if element type is not in scanElements
        if (!in_array($element, $scanElements)) {
            return null;
        }

        $result = $this->scannerService->processExternalUrl($url, $source, $element);
        $this->results[] = $result;

        return $result;
    }

    /**
     * Configure JavaScript rendering if enabled and dependencies are available.
     */
    protected function configureJsRendering(ScanConfig $config, ?Closure $onMessage): void
    {
        if (!$config->useJsRendering) {
            return;
        }

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

            if ($onMessage !== null) {
                $onMessage('  JavaScript rendering enabled (headless browser)');
            }
        } else {
            if ($onMessage !== null) {
                $onMessage("  Warning: {$depCheck['message']} Falling back to static HTML.");
            }
        }
    }

    /**
     * Configure robots.txt handling and compute effective crawl delays.
     *
     * @return array{0: int, 1: int} The effective [$delayMin, $delayMax] in milliseconds.
     */
    protected function configureRobots(ScanConfig $config, Client $client, ?Closure $onMessage): array
    {
        $delayMin = $config->delayMin;
        $delayMax = $config->delayMax;

        if (!$config->respectRobots) {
            return [$delayMin, $delayMax];
        }

        $this->robotsService->setClient($client);
        $this->robotsService->fetchAndParse($config->baseUrl);

        $robotsDelay = $this->robotsService->getCrawlDelay();
        if ($robotsDelay !== null) {
            $robotsDelayMs = (int) ($robotsDelay * 1000);
            $delayMin = max($delayMin, $robotsDelayMs);
            $delayMax = max($delayMax, $robotsDelayMs);

            if ($onMessage !== null) {
                $onMessage("  Robots.txt Crawl-delay: {$robotsDelay}s (using {$delayMin}ms-{$delayMax}ms delay)");
            }
        }

        $rulesCount = count($this->robotsService->getRules());
        if ($rulesCount > 0 && $onMessage !== null) {
            $onMessage("  Robots.txt: respecting {$rulesCount} Disallow/Allow rule(s)");
        }

        return [$delayMin, $delayMax];
    }

    /**
     * Initialize the BFS queue with the starting URL and optional sitemap URLs.
     */
    protected function initQueue(ScanConfig $config, ?Closure $onMessage): void
    {
        $this->queue->enqueue([
            'url' => $config->baseUrl,
            'depth' => 0,
            'source' => 'start',
            'element' => 'a',
        ]);

        if ($config->useSitemap) {
            $this->discoverFromSitemap($config->baseUrl, $onMessage);
        }
    }

    /**
     * After processing the first (start) URL, detect host redirects and
     * update the base URL to the canonical host.
     *
     * This catches cases like www.example.com → example.com so that
     * subsequent resources aren't flagged as redirect chains.
     */
    protected function resolveCanonicalBaseFromFirstResult(string $startUrl, string $source): void
    {
        if ($this->canonicalBaseResolved || $source !== 'start' || empty($this->results)) {
            return;
        }

        $firstResult = end($this->results);
        $finalUrl = rtrim($firstResult['finalUrl'] ?? $startUrl, '/');
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
            $this->visited[$this->scannerService->canonicalUrlKey($finalUrl)] = true;

            // Rewrite all URLs currently in the queue to use canonical host
            $this->rewriteQueueToCanonicalHost();
        }

        $this->canonicalBaseResolved = true;
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
            $onMessage('  Discovering URLs from sitemap...');
        }

        $result = $this->sitemapService->discoverUrls($baseUrl);

        if ($result['count'] > 0) {
            foreach ($result['urls'] as $urlData) {
                $sitemapUrlKey = $this->scannerService->canonicalUrlKey($urlData['url']);
                if (!isset($this->visited[$sitemapUrlKey])) {
                    $this->queue->enqueue([
                        'url' => $urlData['url'],
                        'depth' => 0,
                        'source' => $urlData['source'],
                        'element' => 'a',
                    ]);
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
     *
     * Drains both queues and rebuilds them, preserving priority semantics.
     */
    protected function rewriteQueueToCanonicalHost(): void
    {
        // Drain and rebuild the priority queue
        $newPriorityQueue = new \SplQueue();
        while (!$this->priorityQueue->isEmpty()) {
            $item = $this->priorityQueue->dequeue();
            $item['url'] = $this->rewriteUrlToCanonicalHost($item['url']);
            $itemKey = $this->scannerService->canonicalUrlKey($item['url']);
            if (!isset($this->visited[$itemKey])) {
                $newPriorityQueue->enqueue($item);
            }
        }
        $this->priorityQueue = $newPriorityQueue;

        // Drain and rebuild the normal queue
        $newQueue = new \SplQueue();
        while (!$this->queue->isEmpty()) {
            $item = $this->queue->dequeue();
            $item['url'] = $this->rewriteUrlToCanonicalHost($item['url']);
            $itemKey = $this->scannerService->canonicalUrlKey($item['url']);
            if (!isset($this->visited[$itemKey])) {
                $newQueue->enqueue($item);
            }
        }
        $this->queue = $newQueue;
    }
}

