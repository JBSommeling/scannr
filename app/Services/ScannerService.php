<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for scanning websites and checking link health.
 *
 * This service handles the core functionality of crawling websites,
 * following redirects, extracting links, and validating URL status codes.
 */
class ScannerService
{
    /**
     * Maximum number of redirects to follow.
     */
    protected int $maxRedirects = 5;

    /**
     * The HTTP client instance.
     */
    protected Client $client;

    /**
     * The base URL being scanned.
     */
    protected string $baseUrl = '';

    /**
     * The base host for internal URL detection.
     */
    protected string $baseHost = '';

    /**
     * Create a new ScannerService instance.
     *
     * @param  Client|null  $client  Optional Guzzle HTTP client instance. If not provided, a default client will be created.
     */
    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; SiteScanner/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
    }

    /**
     * Set the HTTP client instance.
     *
     * @param  Client  $client  The Guzzle HTTP client to use for requests.
     * @return $this
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Set the maximum number of redirects to follow.
     *
     * @param  int  $maxRedirects  Maximum redirect hops (default: 5).
     * @return $this
     */
    public function setMaxRedirects(int $maxRedirects): self
    {
        $this->maxRedirects = $maxRedirects;
        return $this;
    }

    /**
     * Set the base URL for scanning.
     *
     * This determines which URLs are considered internal vs external.
     *
     * @param  string  $baseUrl  The base URL of the website (e.g., 'https://example.com').
     * @return $this
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $parsed = parse_url($this->baseUrl);
        $this->baseHost = $parsed['host'] ?? '';
        return $this;
    }

    /**
     * Get the current base URL.
     *
     * @return string The base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the current base host.
     *
     * @return string The base host.
     */
    public function getBaseHost(): string
    {
        return $this->baseHost;
    }

    /**
     * Check the status of a URL by following redirects.
     *
     * Performs an HTTP request and follows any redirect chains up to the
     * configured maximum. Returns detailed information about the final
     * response and any redirects encountered.
     *
     * @param  string  $url     The URL to check.
     * @param  string  $method  HTTP method to use ('GET' or 'HEAD'). Default: 'GET'.
     * @return array{
     *     finalStatus: int|string,
     *     chain: array<string>,
     *     loop: bool,
     *     body: string|null,
     *     hasHttpsDowngrade: bool
     * } Result array containing final status, redirect chain, loop detection, response body (for GET), and HTTPS downgrade flag.
     */
    public function checkUrl(string $url, string $method = 'GET'): array
    {
        return $this->followRedirects($url, $method);
    }

    /**
     * Follow redirects for a given URL.
     *
     * Handles 3xx redirect responses by following the Location header.
     * Detects redirect loops and HTTPS to HTTP downgrades.
     *
     * @param string $url The URL to request.
     * @param string $method HTTP method ('GET' or 'HEAD').
     * @return array{
     *     finalStatus: int|string,
     *     chain: array<string>,
     *     loop: bool,
     *     body: string|null,
     *     hasHttpsDowngrade: bool
     * }
     * @throws GuzzleException
     */
    public function followRedirects(string $url, string $method = 'GET'): array
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

    /**
     * Extract all links from HTML content.
     *
     * Parses HTML and extracts href attributes from anchor tags.
     * Filters out javascript:, mailto:, tel:, and fragment-only links.
     * Normalizes relative URLs to absolute URLs.
     *
     * @param  string  $html       The HTML content to parse.
     * @param  string  $sourceUrl  The URL the HTML was fetched from (for resolving relative URLs).
     * @return array<array{url: string, source: string}> Array of extracted links with URL and source page.
     */
    public function extractLinks(string $html, string $sourceUrl): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html, $sourceUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
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

                $links[] = [
                    'url' => $normalizedUrl,
                    'source' => $sourceUrl,
                ];
            });
        } catch (\Exception $e) {
            // Silently handle parsing errors
        }

        return $links;
    }

    /**
     * Normalize a URL relative to a base URL.
     *
     * Handles protocol-relative URLs, absolute paths, and relative paths.
     * Removes URL fragments and trailing slashes.
     *
     * @param  string|null  $url      The URL to normalize.
     * @param  string       $baseUrl  The base URL for resolving relative URLs.
     * @return string|null The normalized absolute URL, or null if invalid.
     */
    public function normalizeUrl(?string $url, string $baseUrl): ?string
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

    /**
     * Check if a URL is internal to the base host.
     *
     * A URL is considered internal if its host matches the base host
     * or is a subdomain of the base host.
     *
     * @param  string  $url  The URL to check.
     * @return bool True if the URL is internal.
     */
    public function isInternalUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            return true;
        }

        return $parsed['host'] === $this->baseHost
            || str_ends_with($parsed['host'], '.' . $this->baseHost);
    }

    /**
     * Process an internal URL and return scan result.
     *
     * Checks the URL status, follows redirects, and extracts links
     * from successful HTML responses.
     *
     * @param string $url The internal URL to process.
     * @param string $source The source page where this URL was found.
     * @return array{
     *     url: string,
     *     sourcePage: string,
     *     status: int|string,
     *     type: string,
     *     redirectChain: array<string>,
     *     isOk: bool,
     *     isLoop: bool,
     *     hasHttpsDowngrade: bool,
     *     extractedLinks: array<array{url: string, source: string}>
     * }
     * @throws GuzzleException
     */
    public function processInternalUrl(string $url, string $source): array
    {
        $result = $this->followRedirects($url, 'GET');

        $extractedLinks = [];
        if ($result['finalStatus'] === 200 && $result['body'] !== null) {
            $extractedLinks = $this->extractLinks($result['body'], $url);
        }

        return [
            'url' => $url,
            'sourcePage' => $source,
            'status' => $result['finalStatus'],
            'type' => 'internal',
            'redirectChain' => $result['chain'],
            'isOk' => $result['finalStatus'] >= 200 && $result['finalStatus'] < 300,
            'isLoop' => $result['loop'],
            'hasHttpsDowngrade' => $result['hasHttpsDowngrade'],
            'extractedLinks' => $extractedLinks,
        ];
    }

    /**
     * Process an external URL and return scan result.
     *
     * Uses HEAD request for efficiency since we don't need to parse
     * external page content.
     *
     * @param string $url The external URL to process.
     * @param string $source The source page where this URL was found.
     * @return array{
     *     url: string,
     *     sourcePage: string,
     *     status: int|string,
     *     type: string,
     *     redirectChain: array<string>,
     *     isOk: bool,
     *     isLoop: bool,
     *     hasHttpsDowngrade: bool
     * }
     * @throws GuzzleException
     */
    public function processExternalUrl(string $url, string $source): array
    {
        $result = $this->followRedirects($url, 'HEAD');

        return [
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

    /**
     * Calculate statistics from scan results.
     *
     * Computes counts for OK responses, redirects, broken links,
     * timeouts, and other metrics.
     *
     * @param  array  $results  Array of scan result items.
     * @return array{
     *     total: int,
     *     ok: int,
     *     redirects: int,
     *     broken: int,
     *     timeouts: int,
     *     redirectChainCount: int,
     *     totalRedirectHops: int,
     *     httpsDowngrades: int
     * }
     */
    public function calculateStats(array $results): array
    {
        $total = count($results);
        $ok = count(array_filter($results, fn($r) => $r['isOk'] && empty($r['redirectChain'])));
        $redirects = count(array_filter($results, fn($r) => !empty($r['redirectChain']) && $r['isOk']));
        $broken = count(array_filter($results, fn($r) => !$r['isOk'] && $r['status'] !== 'Timeout'));
        $timeouts = count(array_filter($results, fn($r) => $r['status'] === 'Timeout'));

        // Redirect chain statistics
        $redirectChainCount = count(array_filter($results, fn($r) => !empty($r['redirectChain'])));
        $totalRedirectHops = array_sum(array_map(fn($r) => count($r['redirectChain']), $results));

        // HTTPS downgrade count
        $httpsDowngrades = count(array_filter($results, fn($r) => $r['hasHttpsDowngrade'] ?? false));

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

    /**
     * Filter scan results by status.
     *
     * @param  array   $results  Array of scan result items.
     * @param  string  $filter   Filter type: 'all', 'ok', or 'broken'.
     * @return array Filtered results.
     */
    public function filterResults(array $results, string $filter): array
    {
        return match ($filter) {
            'ok' => array_filter($results, fn($r) => $r['isOk']),
            'broken' => array_filter($results, fn($r) => !$r['isOk']),
            default => $results,
        };
    }
}

