<?php

namespace App\Services;

use App\DTO\LinkAnalysis;
use App\Enums\LinkFlag;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for scanning websites and checking link health.
 *
 * Orchestrates HttpChecker, LinkExtractor, and BrowsershotFetcher
 * to process internal and external URLs.
 */
class ScannerService
{
    /**
     * Optional BrowsershotFetcher for JavaScript rendering.
     */
    protected ?BrowsershotFetcher $browsershotFetcher = null;

    /**
     * Create a new ScannerService instance.
     *
     * @param  HttpChecker    $httpChecker    The HTTP checker for following redirects and checking URLs.
     * @param  LinkExtractor  $linkExtractor  The link extractor for parsing HTML content.
     * @param  UrlNormalizer  $urlNormalizer  The URL normalizer for resolving and classifying URLs.
     * @param  ScanStatistics $scanStatistics The statistics calculator for scan results.
     * @param  LinkFlagService $linkFlagService The service for detecting link flags.
     */
    public function __construct(
        protected HttpChecker $httpChecker,
        protected LinkExtractor $linkExtractor,
        protected UrlNormalizer $urlNormalizer,
        protected ScanStatistics $scanStatistics,
        protected LinkFlagService $linkFlagService,
    ) {}

    /**
     * Set the HTTP client instance on the HttpChecker.
     *
     * @param  Client  $client  The Guzzle HTTP client to use for requests.
     * @return $this
     */
    public function setClient(Client $client): self
    {
        $this->httpChecker->setClient($client);
        return $this;
    }

    /**
     * Set the BrowsershotFetcher for JavaScript rendering.
     *
     * When set, internal pages will be rendered with a headless browser
     * to extract links from JavaScript-rendered content (SPAs).
     *
     * @param  BrowsershotFetcher|null  $fetcher  The fetcher instance, or null to disable.
     * @return $this
     */
    public function setBrowsershotFetcher(?BrowsershotFetcher $fetcher): self
    {
        $this->browsershotFetcher = $fetcher;
        return $this;
    }

    /**
     * Process an internal URL and return scan result.
     *
     * Checks the URL status, follows redirects, and extracts links
     * from successful HTML responses.
     *
     * @param string $url The internal URL to process.
     * @param string $source The source page where this URL was found.
     * @param string $element The HTML element type that contained this URL.
     * @param array<LinkFlag> $discoveryFlags Flags from link discovery/extraction.
     * @return array
     * @throws GuzzleException
     */
    public function processInternalUrl(string $url, string $source, string $element = 'a', array $discoveryFlags = []): array
    {
        // Form endpoints only accept POST, so use POST with empty body.
        if ($element === 'form') {
            return $this->httpChecker->processFormEndpoint($url, $source, 'internal');
        }

        $result = $this->httpChecker->followRedirects($url, 'GET');

        $extractedLinks = [];
        if ($result['finalStatus'] === 200) {
            // When JS rendering is enabled, use Browsershot to get the fully
            // rendered DOM (for SPAs like React/Vue) and extract links from that.
            // Fall back to the Guzzle response body if Browsershot fails.
            $htmlForExtraction = $result['body'];

            if ($this->browsershotFetcher !== null) {
                $renderedResult = $this->browsershotFetcher->fetch($result['finalUrl'] ?? $url);
                if ($renderedResult['status'] === 200 && !empty($renderedResult['body'])) {
                    $htmlForExtraction = $renderedResult['body'];
                }
            }

            if ($htmlForExtraction !== null) {
                $extractedLinks = $this->linkExtractor->extractLinks(
                    $htmlForExtraction,
                    $url,
                    $this->browsershotFetcher !== null,
                );
            }
        }

        $status = $result['finalStatus'];

        // Collect all flags
        $flags = $discoveryFlags;

        // Add HTTP response flags
        $flags = array_merge($flags, $this->linkFlagService->detectFromHttpResponse($status));

        // Add URL-based flags
        $flags = array_merge($flags, $this->linkFlagService->detectFromUrl($url, false));

        // Add redirect flags
        $flags = array_merge($flags, $this->linkFlagService->detectFromRedirect(
            $result['chain'],
            $result['loop'],
            $result['hasHttpsDowngrade']
        ));

        // A bare internal subdomain that responds with 200 is proven alive;
        // clear any flags that might indicate issues
        if ($this->linkFlagService->shouldClearForSubdomain($url, $status)) {
            $flags = array_filter($flags, fn($f) => !in_array($f, [
                LinkFlag::DETECTED_IN_JS_BUNDLE,
                LinkFlag::INDIRECT_REFERENCE,
                LinkFlag::MALFORMED_URL,
                LinkFlag::UNVERIFIED,
            ], true));
        }

        // Build analysis from flags
        $analysis = $this->linkFlagService->buildAnalysis($flags, $status, false);

        // Determine status string
        $statusString = $this->formatStatusString($status);

        return [
            'url' => $url,
            'finalUrl' => $result['finalUrl'],
            'sourcePage' => $source,
            'status' => $statusString,
            'type' => 'internal',
            'sourceElement' => $element,
            'extractedLinks' => $extractedLinks,
            'analysis' => $analysis->toArray(),
            'redirect' => [
                'chain' => $result['chain'],
                'isLoop' => $result['loop'],
                'hasHttpsDowngrade' => $result['hasHttpsDowngrade'],
            ],
            'network' => [
                'retryAfter' => $result['retryAfter'],
            ],
        ];
    }

    /**
     * Process an external URL and return scan result.
     *
     * Uses HEAD request for efficiency since we don't need to parse
     * external page content. Only keeps the first redirect destination —
     * external redirect chains are not actionable for site owners.
     *
     * @param string $url The external URL to process.
     * @param string $source The source page where this URL was found.
     * @param string $element The HTML element type that contained this URL.
     * @param array<LinkFlag> $discoveryFlags Flags from link discovery/extraction.
     * @return array
     * @throws GuzzleException
     */
    public function processExternalUrl(string $url, string $source, string $element = 'a', array $discoveryFlags = []): array
    {
        // Form endpoints only accept POST, so use POST with empty body.
        if ($element === 'form') {
            return $this->httpChecker->processFormEndpoint($url, $source, 'external');
        }

        $result = $this->httpChecker->followRedirects($url, 'HEAD');

        // For external URLs, only keep the first redirect destination.
        // We don't care about external redirect chains, only whether the link works.
        $firstRedirect = !empty($result['chain']) ? [$result['chain'][0]] : [];

        $status = $result['finalStatus'];

        // Collect all flags
        $flags = $discoveryFlags;

        // Add URL-based flags (including external platform detection)
        $flags = array_merge($flags, $this->linkFlagService->detectFromUrl($url, true));

        // Add HTTP response flags
        $flags = array_merge($flags, $this->linkFlagService->detectFromHttpResponse($status));

        // Build analysis from flags
        $analysis = $this->linkFlagService->buildAnalysis($flags, $status, true);

        // Determine status string
        $statusString = $this->formatStatusString($status);

        return [
            'url' => $url,
            'sourcePage' => $source,
            'status' => $statusString,
            'type' => 'external',
            'sourceElement' => $element,
            'analysis' => $analysis->toArray(),
            'redirect' => [
                'chain' => $firstRedirect,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
            ],
            'network' => [
                'retryAfter' => $result['retryAfter'],
            ],
        ];
    }

    /**
     * Format status code/string for output.
     *
     * Converts integer status to string, keeps error strings as-is but lowercase.
     */
    protected function formatStatusString(int|string $status): string
    {
        if (is_int($status)) {
            return (string) $status;
        }

        return strtolower($status);
    }
}

