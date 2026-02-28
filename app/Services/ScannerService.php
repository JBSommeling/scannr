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
     * Optional BrowsershotFetcher for JavaScript rendering.
     */
    protected ?BrowsershotFetcher $browsershotFetcher = null;

    /**
     * Create a new ScannerService instance.
     *
     * @param  Client|null  $client  Optional Guzzle HTTP client instance. If not provided, a default client will be created.
     */
    public function __construct(?Client $client = null)
    {
        $defaultUserAgent = 'ScannrBot/1.0 (+https://scannr.io)';
        try {
            $userAgent = config('scanner.user_agent', $defaultUserAgent) ?? $defaultUserAgent;
        } catch (\Throwable) {
            $userAgent = $defaultUserAgent;
        }

        $this->client = $client ?? new Client([
            'timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => $userAgent,
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
     * - <video>, <audio>, <source>, <object>, <embed> (media/downloads)
     * - <a download> (explicit download links)
     * - Elements with data-href, data-url, data-download, data-src, data-file (JS-triggered downloads)
     * - onclick attributes containing window.location, window.open, or download() calls
     * - Inline <script> contents referencing downloadable file URLs (when --js is enabled)
     * - External <script src=""> JS bundles for downloadable file URLs (when --js is enabled, internal only)
     *
     * Filters out javascript:, mailto:, tel:, and fragment-only links.
     * Normalizes relative URLs to absolute URLs.
     *
     * @param  string  $html               The HTML content to parse.
     * @param  string  $sourceUrl          The URL the HTML was fetched from (for resolving relative URLs).
     * @param  bool    $scanScriptContent  Whether to scan inline <script> contents for download URLs (requires --js).
     * @return array<array{url: string, source: string, element: string}> Array of extracted links with URL, source page, and element type.
     */
    public function extractLinks(string $html, string $sourceUrl, bool $scanScriptContent = false): array
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

            // Extract from <video src="">
            $crawler->filter('video[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <video poster="">
            $crawler->filter('video[poster]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'poster', $sourceUrl, 'media', $links);
            });

            // Extract from <audio src="">
            $crawler->filter('audio[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <source src=""> inside <video> and <audio> elements
            $crawler->filter('video source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });
            $crawler->filter('audio source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <object data="">
            $crawler->filter('object[data]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'data', $sourceUrl, 'media', $links);
            });

            // Extract from <embed src="">
            $crawler->filter('embed[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <a download> (explicit download links, classified as media)
            $crawler->filter('a[download][href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'media', $links);
            });

            // Extract from elements with data attributes commonly used for JS-triggered downloads
            // e.g., <button data-href="/file.pdf">, <div data-download="/report.xlsx">
            foreach (['data-href', 'data-url', 'data-download', 'data-src', 'data-file'] as $attr) {
                $crawler->filter("[{$attr}]")->each(function (Crawler $node) use ($attr, $sourceUrl, &$links) {
                    // Skip img[data-src] — already handled above as 'img' element
                    if ($attr === 'data-src' && strtolower($node->nodeName()) === 'img') {
                        return;
                    }
                    $this->addLinkFromAttribute($node, $attr, $sourceUrl, 'media', $links);
                });
            }

            // Extract download URLs from onclick attributes
            // Matches patterns like: onclick="window.location.href='/file.pdf'"
            //                        onclick="window.open('/file.pdf')"
            //                        onclick="location.href='/file.pdf'"
            //                        onclick="download('/file.pdf')"
            $crawler->filter('[onclick]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $onclick = $node->attr('onclick');
                if ($onclick === null) {
                    return;
                }
                $this->addLinksFromInlineJs($onclick, $sourceUrl, $links);
            });

            // Extract downloadable file URLs from inline <script> contents.
            // Only enabled with --js flag to avoid false positives.
            // Catches URLs in React/Next.js/Nuxt data blobs, JSON config,
            // and JS string literals that reference downloadable files.
            if ($scanScriptContent) {
                $crawler->filter('script:not([src])')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                    $content = $node->text('', false);
                    if ($content === '') {
                        return;
                    }
                    $this->addDownloadUrlsFromScriptContent($content, $sourceUrl, $links);
                });

                // Also fetch and scan external JS bundles for download URLs.
                // Only scans internal scripts (same domain) to avoid fetching
                // third-party CDN bundles. This catches React/Vue/Svelte apps
                // where download URLs are compiled into the JS bundle
                // (e.g., onClick handlers with document.createElement("a").href="/cv.pdf").
                $crawler->filter('script[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                    $src = $node->attr('src');
                    if ($src === null || $src === '') {
                        return;
                    }

                    $scriptUrl = $this->normalizeUrl($src, $sourceUrl);
                    if ($scriptUrl === null || !$this->isInternalUrl($scriptUrl)) {
                        return;
                    }

                    $content = $this->fetchScriptContent($scriptUrl);
                    if ($content !== null) {
                        $this->addDownloadUrlsFromScriptContent($content, $sourceUrl, $links);
                    }
                });
            }
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
     * @param  string   $element    The element type ('a', 'link', 'script', 'img', 'media').
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
     * Extract URLs from inline JavaScript code (e.g., onclick attributes).
     *
     * Matches common patterns:
     * - window.location.href = '/file.pdf'
     * - window.location = '/file.pdf'
     * - location.href = '/file.pdf'
     * - window.open('/file.pdf')
     * - window.open("/file.pdf")
     *
     * @param  string  $js         The inline JavaScript code.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addLinksFromInlineJs(string $js, string $sourceUrl, array &$links): void
    {
        // Match: window.location.href = '...', window.location = '...', location.href = '...'
        // Match: window.open('...'), window.open("...")
        // Match: download('...'), download("...")
        $patterns = [
            '/(?:window\.)?location(?:\.href)?\s*=\s*[\'"]([^\'"]+)[\'"]/i',
            '/window\.open\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
            '/download\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $js, $matches)) {
                foreach ($matches[1] as $url) {
                    // Skip javascript:, mailto:, tel:, data: URLs
                    if (preg_match('/^(javascript|mailto|tel|data|#)/', $url)) {
                        continue;
                    }

                    $normalizedUrl = $this->normalizeUrl($url, $sourceUrl);
                    if ($normalizedUrl !== null) {
                        $links[] = [
                            'url' => $normalizedUrl,
                            'source' => $sourceUrl,
                            'element' => 'media',
                        ];
                    }
                }
            }
        }
    }

    /**
     * Extract downloadable file URLs from inline script content.
     *
     * Scans JavaScript/JSON content for quoted string literals that look like
     * paths to downloadable files. Only matches strings that:
     * - Start with "/" or "http" (to avoid matching random JS identifiers)
     * - End with a known downloadable file extension (from config)
     *
     * This catches download URLs embedded in:
     * - React/Next.js data blobs (__NEXT_DATA__, __NUXT__)
     * - JSON configuration objects
     * - JavaScript string literals referencing file downloads
     *
     * @param  string  $content    The inline script content.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addDownloadUrlsFromScriptContent(string $content, string $sourceUrl, array &$links): void
    {
        $extensions = $this->getDownloadExtensions();

        if (empty($extensions)) {
            return;
        }

        // Unescape JSON forward-slash escaping (e.g., "\/downloads\/file.pdf" → "/downloads/file.pdf")
        $content = str_replace('\\/', '/', $content);

        $extPattern = implode('|', array_map('preg_quote', $extensions));

        // Match quoted strings (single or double) that:
        // 1. Start with "/" or "http" (path or absolute URL)
        // 2. End with a downloadable file extension
        // 3. Don't contain whitespace (URLs don't have spaces)
        // 4. Don't contain quotes (prevent greedy matching)
        $pattern = '/[\'\"]((?:\/|https?:\/\/)[^\s\'"]*\.(?:' . $extPattern . '))[\'\"]/i';

        if (preg_match_all($pattern, $content, $matches)) {
            $seen = [];
            foreach ($matches[1] as $url) {
                // Deduplicate within this script block
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                // Skip data: URIs and fragments
                if (preg_match('/^(data|#)/', $url)) {
                    continue;
                }

                $normalizedUrl = $this->normalizeUrl($url, $sourceUrl);
                if ($normalizedUrl !== null) {
                    $links[] = [
                        'url' => $normalizedUrl,
                        'source' => $sourceUrl,
                        'element' => 'media',
                    ];
                }
            }
        }
    }

    /**
     * Get the list of downloadable file extensions from config.
     *
     * @return array<string>
     */
    protected function getDownloadExtensions(): array
    {
        try {
            return config('scanner.download_extensions', []);
        } catch (\Throwable) {
            return [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
                'rtf', 'txt', 'odt', 'ods', 'odp', 'epub',
                'zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz',
                'mp3', 'mp4', 'wav', 'avi', 'mov', 'wmv', 'flv', 'webm', 'ogg', 'mkv',
                'dmg', 'exe', 'msi', 'deb', 'rpm', 'apk', 'ipa',
                'svg', 'psd', 'ai', 'eps',
            ];
        }
    }

    /**
     * Fetch the content of an external script file.
     *
     * Makes a GET request to retrieve the JavaScript bundle content.
     * Used when --js is enabled to scan external bundles for download URLs.
     *
     * @param  string  $url  The absolute URL of the script file.
     * @return string|null   The script content, or null on failure.
     */
    protected function fetchScriptContent(string $url): ?string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'allow_redirects' => true,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return (string) $response->getBody();
        } catch (\Exception $e) {
            return null;
        }
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
     * Generate a canonical key for a URL, suitable for visited-set deduplication.
     *
     * Strips fragments, tracking parameters, and trailing slashes, and
     * lowercases the host portion for case-insensitive host matching.
     * The path portion remains case-sensitive per RFC 3986.
     *
     * @param  string  $url  An absolute URL.
     * @return string The canonical URL key.
     */
    public function canonicalUrlKey(string $url): string
    {
        // Strip fragment
        $url = preg_replace('/#.*$/', '', $url);

        // Strip tracking params, then trailing slash
        // Note: stripTrackingParams rebuilds the URL from parsed components,
        // so trailing slash removal must happen after it.
        $url = rtrim($this->stripTrackingParams($url), '/');

        // Lowercase the host portion only (RFC 3986: host is case-insensitive)
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            $lowerHost = strtolower($parsed['host']);
            if ($lowerHost !== $parsed['host']) {
                $url = preg_replace(
                    '/^(' . preg_quote($parsed['scheme'] ?? 'https', '/') . ':\/\/)' . preg_quote($parsed['host'], '/') . '/',
                    '$1' . $lowerHost,
                    $url,
                    1
                );
            }
        }

        return $url;
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
                $extractedLinks = $this->extractLinks(
                    $htmlForExtraction,
                    $url,
                    $this->browsershotFetcher !== null,
                );
            }
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
     * @param string $element The HTML element type that contained this URL ('a', 'link', 'script', 'img', 'media').
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

        // Redirect chain statistics (a "chain" is 2+ hops; single redirects are just redirects)
        $redirectChainCount = count(array_filter($results, fn($r) => count($r['redirectChain'] ?? []) >= 2));
        $totalRedirectHops = array_sum(array_map(fn($r) => count($r['redirectChain'] ?? []), $results));

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
     * @param  string  $element  Element filter: 'all', 'a', 'link', 'script', 'img', or 'media'.
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

