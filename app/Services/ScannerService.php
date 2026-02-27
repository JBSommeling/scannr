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
    * List of tracking parameters to strip from URLs.
    *
    * Supports exact matches (e.g., 'fbclid') and prefix matches with '*' suffix (e.g., 'utm_*').
    * Matching is case-insensitive.
    */
    protected array $trackingParams = [];

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

        // Default tracking params
        $defaults = [
            'utm_*',
            'fbclid',
            'gclid',
            'ref',
            'source',
        ];

        // Load from config if Laravel is bootstrapped, otherwise use defaults
        try {
            $this->trackingParams = config('scanner.tracking_params') ?? $defaults;
        } catch (\Throwable) {
            $this->trackingParams = $defaults;
        }
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
     * The www. prefix is normalized (removed) for consistent matching.
     *
     * @param  string  $baseUrl  The base URL of the website (e.g., 'https://example.com').
     * @return $this
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $parsed = parse_url($this->baseUrl);
        $host = $parsed['host'] ?? '';

        // Normalize host by removing www. prefix for consistent matching
        $this->baseHost = preg_replace('/^www\./i', '', $host);

        // Also normalize the baseUrl to use the non-www version
        if (stripos($host, 'www.') === 0) {
            $this->baseUrl = str_ireplace('://www.', '://', $this->baseUrl);
        }

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
     * Set the tracking parameters to strip from URLs.
     *
     * @param  array<string>  $params  Array of parameter names. Use '*' suffix for prefix matching.
     * @return $this
     */
    public function setTrackingParams(array $params): self
    {
        $this->trackingParams = $params;
        return $this;
    }

    /**
     * Add additional tracking parameters to strip from URLs.
     *
     * @param  array<string>  $params  Array of parameter names to add. Use '*' suffix for prefix matching.
     * @return $this
     */
    public function addTrackingParams(array $params): self
    {
        $this->trackingParams = array_unique(array_merge($this->trackingParams, $params));
        return $this;
    }

    /**
     * Get the current tracking parameters.
     *
     * @return array<string> The tracking parameters.
     */
    public function getTrackingParams(): array
    {
        return $this->trackingParams;
    }

    /**
     * Check the status of a URL by following redirects.
     *
     * Performs an HTTP request and follows any redirect chains up to the
     * configured maximum. Returns detailed information about the final
     * response and any redirects encountered.
     *
     * @param string $url The URL to check.
     * @param string $method HTTP method to use ('GET' or 'HEAD'). Default: 'GET'.
     * @return array{
     *     finalStatus: int|string,
     *     chain: array<string>,
     *     loop: bool,
     *     body: string|null,
     *     hasHttpsDowngrade: bool
     * } Result array containing final status, redirect chain, loop detection, response body (for GET), and HTTPS downgrade flag.
     * @throws GuzzleException
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
     * www-to-non-www redirects (and vice versa) are not counted as hops
     * and are excluded from the redirect chain.
     *
     * @param string $url The URL to request.
     * @param string $method HTTP method ('GET' or 'HEAD').
     * @return array{
     *     finalStatus: int|string,
     *     finalUrl: string,
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

        // Keep track of visited URLs to detect loops
        $visitedUrls = [];
        $visitedUrls[$url] = true;

        while ($hops < $this->maxRedirects) {
            try {
                $response = $this->client->request($method, $currentUrl);
                $finalStatus = $response->getStatusCode();

                // If 3xx redirect
                if ($finalStatus >= 300 && $finalStatus < 400) {
                    $rawLocation = $response->getHeaderLine('Location');

                    if (empty($rawLocation)) {
                        break;
                    }

                    // Resolve relative redirect location to absolute URL (but keep trailing slashes)
                    $location = $this->resolveRedirectUrl($rawLocation, $currentUrl);

                    if ($location === null) {
                        break;
                    }

                    // Check for HTTPS to HTTP downgrade
                    $currentScheme = parse_url($currentUrl, PHP_URL_SCHEME);
                    $locationScheme = parse_url($location, PHP_URL_SCHEME);
                    if ($currentScheme === 'https' && $locationScheme === 'http') {
                        $hasHttpsDowngrade = true;
                    }

                    // Check for loop (redirecting to an already visited URL)
                    if (isset($visitedUrls[$location])) {
                        $loop = true;
                        $chain[] = $location . ' (LOOP)';
                        break;
                    }

                    $visitedUrls[$location] = true;

                    // Skip www-only redirects from the chain (but still follow them)
                    if (!$this->isWwwOnlyRedirect($currentUrl, $location)) {
                        $chain[] = $location;
                        $hops++;
                    }

                    $currentUrl = $location;
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
            'finalUrl' => $currentUrl,
            'chain' => $chain,
            'loop' => $loop,
            'body' => $body,
            'hasHttpsDowngrade' => $hasHttpsDowngrade,
        ];
    }

    /**
     * Check if a redirect is only a www normalization (www to non-www or vice versa).
     *
     * @param string $fromUrl The original URL.
     * @param string $toUrl The redirect target URL.
     * @return bool True if the redirect only changes the www prefix.
     */
    protected function isWwwOnlyRedirect(string $fromUrl, string $toUrl): bool
    {
        $fromParsed = parse_url($fromUrl);
        $toParsed = parse_url($toUrl);

        if (!isset($fromParsed['host']) || !isset($toParsed['host'])) {
            return false;
        }

        // Normalize both hosts by removing www prefix
        $fromHost = preg_replace('/^www\./i', '', $fromParsed['host']);
        $toHost = preg_replace('/^www\./i', '', $toParsed['host']);

        // If hosts are different after normalization, it's not a www-only redirect
        if ($fromHost !== $toHost) {
            return false;
        }

        // Check if the only difference is the www prefix
        $fromWithoutHost = ($fromParsed['scheme'] ?? '') . '://' .
                          ($fromParsed['path'] ?? '/') .
                          (isset($fromParsed['query']) ? '?' . $fromParsed['query'] : '');
        $toWithoutHost = ($toParsed['scheme'] ?? '') . '://' .
                        ($toParsed['path'] ?? '/') .
                        (isset($toParsed['query']) ? '?' . $toParsed['query'] : '');

        // Same scheme, path, and query = www-only redirect
        return $fromWithoutHost === $toWithoutHost;
    }

    /**
     * Extract all links from HTML content.
     *
     * Parses HTML and extracts URLs from:
     * - <a href=""> (anchor links)
     * - <link href=""> (stylesheets, icons, etc.)
     * - <script src=""> (JavaScript files)
     * - <img src=""> (images)
     *
     * Filters out javascript:, mailto:, tel:, and fragment-only links.
     * Normalizes relative URLs to absolute URLs.
     *
     * @param  string  $html       The HTML content to parse.
     * @param  string  $sourceUrl  The URL the HTML was fetched from (for resolving relative URLs).
     * @return array<array{url: string, source: string, element: string}> Array of extracted links with URL, source page, and element type.
     */
    public function extractLinks(string $html, string $sourceUrl): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html, $sourceUrl);

            // Extract from <a href="">
            $crawler->filter('a[href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'a', $links);
            });

            // Extract from <link href=""> (stylesheets, icons, etc.)
            $crawler->filter('link[href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'link', $links);
            });

            // Extract from <script src="">
            $crawler->filter('script[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'script', $links);
            });

            // Extract from <img src="">
            $crawler->filter('img[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'img', $links);
            });

            // Extract from <img srcset=""> (responsive images)
            $crawler->filter('img[srcset]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinksFromSrcset($node, $sourceUrl, 'img', $links);
            });

            // Extract from <img data-src=""> (lazy loading)
            $crawler->filter('img[data-src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'data-src', $sourceUrl, 'img', $links);
            });

            // Extract from <source srcset=""> inside <picture> elements
            $crawler->filter('picture source[srcset]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinksFromSrcset($node, $sourceUrl, 'img', $links);
            });

            // Extract from <source src=""> inside <picture> elements
            $crawler->filter('picture source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'img', $links);
            });
        } catch (\Exception $e) {
            // Silently handle parsing errors
        }

        return $links;
    }

    /**
     * Add a link from an element attribute to the links array.
     *
     * @param  Crawler  $node       The DOM node to extract from.
     * @param  string   $attribute  The attribute name ('href' or 'src').
     * @param  string   $sourceUrl  The source page URL.
     * @param  string   $element    The element type ('a', 'link', 'script', 'img').
     * @param  array    &$links     Reference to the links array.
     * @return void
     */
    protected function addLinkFromAttribute(Crawler $node, string $attribute, string $sourceUrl, string $element, array &$links): void
    {
        $value = $node->attr($attribute);

        if ($value === null || $value === '') {
            return;
        }

        // Skip javascript:, mailto:, tel:, data:, etc.
        if (preg_match('/^(javascript|mailto|tel|data|#)/', $value)) {
            return;
        }

        $normalizedUrl = $this->normalizeUrl($value, $sourceUrl);

        if ($normalizedUrl === null) {
            return;
        }

        $links[] = [
            'url' => $normalizedUrl,
            'source' => $sourceUrl,
            'element' => $element,
        ];
    }

    /**
     * Add links from a srcset attribute to the links array.
     *
     * Parses the srcset format which contains multiple URLs with size descriptors
     * e.g., "image-320w.jpg 320w, image-480w.jpg 480w, image-800w.jpg 800w"
     *
     * @param  Crawler  $node       The DOM node to extract from.
     * @param  string   $sourceUrl  The source page URL.
     * @param  string   $element    The element type ('img').
     * @param  array    &$links     Reference to the links array.
     * @return void
     */
    protected function addLinksFromSrcset(Crawler $node, string $sourceUrl, string $element, array &$links): void
    {
        $srcset = $node->attr('srcset');

        if ($srcset === null || $srcset === '') {
            return;
        }

        // Split srcset on comma followed by optional whitespace, then find the URL in each candidate
        // This regex splits correctly for standard srcset but data: URLs are complex
        // Standard srcset format: "url descriptor, url descriptor, ..."
        $candidates = preg_split('/,\s*(?=[^\s])/', $srcset);

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            // Split by whitespace - first part is the URL, rest is descriptor (e.g., "320w" or "2x")
            $parts = preg_split('/\s+/', $candidate, 2);
            $url = $parts[0] ?? '';

            if ($url === '') {
                continue;
            }

            // Skip data: URLs and fragment-only
            if (preg_match('/^(data:|#)/', $url)) {
                continue;
            }

            $normalizedUrl = $this->normalizeUrl($url, $sourceUrl);

            if ($normalizedUrl === null) {
                continue;
            }

            // Avoid duplicates in links array
            $alreadyAdded = false;
            foreach ($links as $link) {
                if ($link['url'] === $normalizedUrl && $link['element'] === $element) {
                    $alreadyAdded = true;
                    break;
                }
            }

            if (!$alreadyAdded) {
                $links[] = [
                    'url' => $normalizedUrl,
                    'source' => $sourceUrl,
                    'element' => $element,
                ];
            }
        }
    }

    /**
     * Normalize a URL relative to a base URL.
     *
     * Handles protocol-relative URLs, absolute paths, and relative paths.
     * Removes URL fragments, trailing slashes, and known tracking parameters
     * (utm_*, fbclid, gclid, ref, source) in a case-insensitive manner.
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
            return $this->stripTrackingParams(rtrim($url, '/'));
        }

        // Handle relative URLs
        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($url, '/')) {
            // Absolute path
            return $this->stripTrackingParams(rtrim("{$scheme}://{$host}{$port}{$url}", '/'));
        }

        // Relative path
        $basePath = $parsedBase['path'] ?? '/';
        $basePath = preg_replace('/\/[^\/]*$/', '/', $basePath);

        return $this->stripTrackingParams(rtrim("{$scheme}://{$host}{$port}{$basePath}{$url}", '/'));
    }

    /**
     * Resolve a redirect URL to an absolute URL.
     *
     * Similar to normalizeUrl but preserves trailing slashes and does not
     * strip tracking parameters. Used for following redirects accurately.
     *
     * @param  string|null  $url      The redirect URL to resolve.
     * @param  string       $baseUrl  The current URL for resolving relative URLs.
     * @return string|null The absolute URL, or null if invalid.
     */
    public function resolveRedirectUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Remove fragment only
        $url = preg_replace('/#.*$/', '', $url);

        if ($url === '') {
            return null;
        }

        // Handle protocol-relative URLs
        if (str_starts_with($url, '//')) {
            $parsedBase = parse_url($baseUrl);
            return ($parsedBase['scheme'] ?? 'https') . ':' . $url;
        }

        // Handle absolute URLs - return as-is (no trailing slash stripping)
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        // Handle relative URLs
        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($url, '/')) {
            // Absolute path
            return "{$scheme}://{$host}{$port}{$url}";
        }

        // Relative path
        $basePath = $parsedBase['path'] ?? '/';
        $basePath = preg_replace('/\/[^\/]*$/', '/', $basePath);

        return "{$scheme}://{$host}{$port}{$basePath}{$url}";
    }

    /**
     * Strip tracking parameters from a URL.
     *
     * Removes known tracking parameters (utm_*, fbclid, gclid, ref, source)
     * in a case-insensitive manner. Supports prefix matching with '*' suffix.
     *
     * @param  string  $url  The URL to strip tracking parameters from.
     * @return string The URL without tracking parameters.
     */
    protected function stripTrackingParams(string $url): string
    {
        $parsed = parse_url($url);

        if (!isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $queryParams);

        $filteredParams = [];
        foreach ($queryParams as $key => $value) {
            if (!$this->isTrackingParam($key)) {
                $filteredParams[$key] = $value;
            }
        }

        // Rebuild URL without tracking params
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        $newUrl = "{$scheme}://{$host}{$port}{$path}";

        if (!empty($filteredParams)) {
            $newUrl .= '?' . http_build_query($filteredParams);
        }

        return $newUrl;
    }

    /**
     * Check if a parameter name matches a tracking parameter pattern.
     *
     * @param  string  $paramName  The parameter name to check.
     * @return bool True if the parameter is a tracking parameter.
     */
    protected function isTrackingParam(string $paramName): bool
    {
        $paramNameLower = strtolower($paramName);

        foreach ($this->trackingParams as $pattern) {
            $patternLower = strtolower($pattern);

            // Check for prefix wildcard (e.g., 'utm_*')
            if (str_ends_with($patternLower, '*')) {
                $prefix = substr($patternLower, 0, -1);
                if (str_starts_with($paramNameLower, $prefix)) {
                    return true;
                }
            } elseif ($paramNameLower === $patternLower) {
                // Exact match (case-insensitive)
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL is internal to the base host.
     *
     * A URL is considered internal if its host matches the base host
     * or is a subdomain of the base host. Handles www/non-www equivalence
     * by normalizing both hosts.
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

        // Normalize URL host by removing www. prefix
        $urlHost = preg_replace('/^www\./i', '', $parsed['host']);

        // Exact match (both are now normalized without www)
        if ($urlHost === $this->baseHost) {
            return true;
        }

        // Check if URL host is a subdomain of base host
        if (str_ends_with($urlHost, '.' . $this->baseHost)) {
            return true;
        }

        return false;
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
     *     finalUrl: string,
     *     sourcePage: string,
     *     status: int|string,
     *     type: string,
     *     redirectChain: array<string>,
     *     isOk: bool,
     *     isLoop: bool,
     *     hasHttpsDowngrade: bool,
     *     sourceElement: string,
     *     extractedLinks: array<array{url: string, source: string, element: string}>
     * }
     * @throws GuzzleException
     */
    public function processInternalUrl(string $url, string $source, string $element = 'a'): array
    {
        $result = $this->followRedirects($url, 'GET');

        $extractedLinks = [];
        if ($result['finalStatus'] === 200 && $result['body'] !== null) {
            $extractedLinks = $this->extractLinks($result['body'], $url);
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
     * @param string $element The HTML element type that contained this URL ('a', 'link', 'script', 'img').
     * @return array{
     *     url: string,
     *     sourcePage: string,
     *     status: int|string,
     *     type: string,
     *     redirectChain: array<string>,
     *     isOk: bool,
     *     isLoop: bool,
     *     hasHttpsDowngrade: bool,
     *     sourceElement: string
     * }
     * @throws GuzzleException
     */
    public function processExternalUrl(string $url, string $source, string $element = 'a'): array
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
            'sourceElement' => $element,
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

    /**
     * Filter scan results by source element type.
     *
     * @param  array   $results  Array of scan result items.
     * @param  string  $element  Element filter: 'all', 'a', 'link', 'script', or 'img'.
     * @return array Filtered results.
     */
    public function filterByElement(array $results, string $element): array
    {
        if ($element === 'all') {
            return $results;
        }

        return array_filter($results, fn($r) => ($r['sourceElement'] ?? 'a') === $element);
    }
}

