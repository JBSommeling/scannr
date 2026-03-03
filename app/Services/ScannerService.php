<?php

namespace App\Services;

use App\DTO\VerificationStatus;
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
     * @param  VerificationService $verificationService The verification service for detecting verification needs.
     */
    public function __construct(
        protected HttpChecker $httpChecker,
        protected LinkExtractor $linkExtractor,
        protected UrlNormalizer $urlNormalizer,
        protected ScanStatistics $scanStatistics,
        protected VerificationService $verificationService,
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
     * @param VerificationStatus $verification The verification status from extraction.
     * @return array
     * @throws GuzzleException
     */
    public function processInternalUrl(string $url, string $source, string $element = 'a', ?VerificationStatus $verification = null): array
    {
        $verification ??= VerificationStatus::none();

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

        // A bare internal subdomain that responds with 200 is proven alive;
        // no manual verification is ever needed regardless of how it was flagged.
        if ($this->verificationService->shouldClearForSubdomain($url, $result['finalStatus'])) {
            $verification = VerificationStatus::none();
        }

        return [
            'url' => $url,
            'finalUrl' => $result['finalUrl'],
            'sourcePage' => $source,
            'status' => $result['finalStatus'],
            'type' => 'internal',
            'redirectChain' => $result['chain'],
            'isOk' => $result['finalStatus'] >= 200 && $result['finalStatus'] < 300,
            'isLoop' => $result['loop'],
            'hasHttpsDowngrade' => $result['hasHttpsDowngrade'],
            'sourceElement' => $element,
            'extractedLinks' => $extractedLinks,
            'retryAfter' => $result['retryAfter'],
            ...$verification->toArray(),
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
     * @param VerificationStatus $verification The verification status from extraction.
     * @return array
     * @throws GuzzleException
     */
    public function processExternalUrl(string $url, string $source, string $element = 'a', ?VerificationStatus $verification = null): array
    {
        $verification ??= VerificationStatus::none();

        // Form endpoints only accept POST, so use POST with empty body.
        if ($element === 'form') {
            return $this->httpChecker->processFormEndpoint($url, $source, 'external');
        }

        $result = $this->httpChecker->followRedirects($url, 'HEAD');

        // For external URLs, only keep the first redirect destination.
        // We don't care about external redirect chains, only whether the link works.
        $firstRedirect = !empty($result['chain']) ? [$result['chain'][0]] : [];

        // Detect bot protection and merge with existing verification status
        $status = $result['finalStatus'];
        $httpVerification = $this->verificationService->detectFromHttpResponse($status);
        $verification = $verification->merge($httpVerification);

        return [
            'url' => $url,
            'sourcePage' => $source,
            'status' => $status,
            'type' => 'external',
            'redirectChain' => $firstRedirect,
            'isOk' => is_int($status) && $status >= 200 && $status < 300,
            'isLoop' => false,
            'hasHttpsDowngrade' => false,
            'sourceElement' => $element,
            'retryAfter' => $result['retryAfter'],
            ...$verification->toArray(),
        ];
    }
}

