<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for discovering and parsing sitemap files.
 *
 * This service handles the discovery and parsing of various sitemap formats
 * including XML sitemaps (standard and index), HTML sitemaps, and plain text sitemaps.
 * It supports automatic discovery from robots.txt and common sitemap locations.
 */
class SitemapService
{
    /**
     * Maximum recursion depth for sitemap index files.
     */
    protected const MAX_SITEMAP_DEPTH = 3;

    /**
     * The HTTP client instance.
     */
    protected Client $client;

    /**
     * The base URL being scanned.
     */
    protected string $baseUrl;

    /**
     * The base host for internal URL detection.
     */
    protected string $baseHost;

    /**
     * Create a new SitemapService instance.
     *
     * @param  Client|null  $client  Optional Guzzle HTTP client instance. If not provided, a default client will be created.
     */
    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 10,
            'allow_redirects' => true,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; SitemapScanner/1.0)',
                'Accept' => 'text/xml,application/xml,text/html,text/plain,*/*',
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
     * Set the base URL for sitemap discovery.
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
     * Discover URLs from sitemap files for a given website.
     *
     * This method attempts to locate and parse sitemap files by:
     * 1. Checking robots.txt for sitemap declarations
     * 2. Trying common sitemap locations (sitemap.xml, sitemap_index.xml, sitemap/)
     *
     * @param  string  $baseUrl  The base URL of the website to scan.
     * @return array{urls: array<array{url: string, source: string}>, count: int} An array containing discovered URLs and total count.
     */
    public function discoverUrls(string $baseUrl): array
    {
        $this->setBaseUrl($baseUrl);

        $sitemapUrls = [
            $this->baseUrl . '/sitemap.xml',
            $this->baseUrl . '/sitemap_index.xml',
            $this->baseUrl . '/sitemap/',
        ];

        // First try to get sitemap URL from robots.txt
        $robotsSitemaps = $this->getSitemapsFromRobots();
        $sitemapUrls = array_merge($robotsSitemaps, $sitemapUrls);

        $discoveredUrls = [];
        foreach ($sitemapUrls as $sitemapUrl) {
            $sitemapUrl = trim($sitemapUrl);
            $urls = $this->parseSitemap($sitemapUrl);

            if (!empty($urls)) {
                $discoveredUrls = array_merge($discoveredUrls, $urls);
                break; // Found a working sitemap
            }
        }

        // Filter to internal URLs only and remove duplicates
        $filteredUrls = [];
        $seen = [];
        foreach ($discoveredUrls as $url) {
            $normalizedUrl = rtrim($url, '/');
            if (!isset($seen[$normalizedUrl]) && $this->isInternalUrl($normalizedUrl)) {
                $seen[$normalizedUrl] = true;
                $filteredUrls[] = [
                    'url' => $normalizedUrl,
                    'source' => 'sitemap',
                ];
            }
        }

        return [
            'urls' => $filteredUrls,
            'count' => count($filteredUrls),
        ];
    }

    /**
     * Get sitemap URLs declared in robots.txt.
     *
     * Parses the robots.txt file to extract any Sitemap: directives.
     *
     * @return array<string> Array of sitemap URLs found in robots.txt.
     * @throws GuzzleException
     */
    public function getSitemapsFromRobots(): array
    {
        $robotsUrl = $this->baseUrl . '/robots.txt';

        try {
            $response = $this->client->request('GET', $robotsUrl);

            if ($response->getStatusCode() === 200) {
                $robotsContent = (string) $response->getBody();

                if (preg_match_all('/Sitemap:\s*(.+)/i', $robotsContent, $matches)) {
                    return array_map('trim', $matches[1]);
                }
            }
        } catch (\Exception $e) {
            // Ignore robots.txt errors
        }

        return [];
    }

    /**
     * Parse a sitemap from a given URL.
     *
     * Supports XML sitemaps (standard and index), HTML sitemaps, and plain text sitemaps.
     * Automatically detects the format based on content type and content inspection.
     *
     * @param string $url The URL of the sitemap to parse.
     * @param int $depth Current recursion depth (used for sitemap index files).
     * @return array<string> Array of discovered page URLs.
     * @throws GuzzleException
     */
    public function parseSitemap(string $url, int $depth = 0): array
    {
        if ($depth > self::MAX_SITEMAP_DEPTH) {
            return []; // Prevent infinite recursion in sitemap indexes
        }

        try {
            $response = $this->client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $content = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type');

            // Determine format and parse accordingly
            if ($this->isXmlContent($content, $contentType)) {
                return $this->parseXmlSitemap($content, $depth);
            }

            if ($this->isHtmlContent($content, $contentType)) {
                return $this->parseHtmlSitemap($content, $url);
            }

            // Try plain text (one URL per line)
            if ($this->isTextContent($contentType)) {
                return $this->parseTextSitemap($content);
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Determine if content is XML based on content type and content inspection.
     *
     * Checks both the Content-Type header and the actual content for XML indicators
     * such as XML declarations or standard sitemap root elements.
     *
     * @param  string  $content      The response body content.
     * @param  string  $contentType  The Content-Type header value.
     * @return bool True if the content appears to be XML.
     */
    public function isXmlContent(string $content, string $contentType): bool
    {
        // Check content type header
        if (str_contains($contentType, 'xml')) {
            return true;
        }

        // Check if content starts with XML declaration or root element
        $trimmed = ltrim($content);
        return str_starts_with($trimmed, '<?xml') ||
               str_starts_with($trimmed, '<urlset') ||
               str_starts_with($trimmed, '<sitemapindex');
    }

    /**
     * Determine if content is HTML based on content type and content inspection.
     *
     * Checks both the Content-Type header and the actual content for HTML indicators
     * such as DOCTYPE declarations or HTML root elements.
     *
     * @param  string  $content      The response body content.
     * @param  string  $contentType  The Content-Type header value.
     * @return bool True if the content appears to be HTML.
     */
    public function isHtmlContent(string $content, string $contentType): bool
    {
        // Check content type header
        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        // Check if content looks like HTML
        $trimmed = ltrim($content);
        return str_starts_with($trimmed, '<!DOCTYPE') ||
               str_starts_with($trimmed, '<html') ||
               str_starts_with($trimmed, '<HTML');
    }

    /**
     * Determine if content is plain text based on content type.
     *
     * @param  string  $contentType  The Content-Type header value.
     * @return bool True if the content type indicates plain text.
     */
    public function isTextContent(string $contentType): bool
    {
        return str_contains($contentType, 'text/plain');
    }

    /**
     * Parse an XML sitemap and extract URLs.
     *
     * Handles both standard sitemaps (<urlset>) and sitemap index files (<sitemapindex>).
     * Supports both namespaced and non-namespaced XML formats.
     *
     * @param string $content The XML sitemap content.
     * @param int $depth Current recursion depth for sitemap index processing.
     * @return array<string> Array of discovered page URLs.
     * @throws GuzzleException
     */
    public function parseXmlSitemap(string $content, int $depth = 0): array
    {
        // Suppress XML errors
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();

        if ($xml === false) {
            return [];
        }

        $urls = [];

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
                $childUrls = $this->parseSitemap($childSitemapUrl, $depth + 1);
                $urls = array_merge($urls, $childUrls);
            }
            return $urls;
        }

        // Parse regular sitemap URLs
        $urlNodes = $xml->xpath('//sm:url/sm:loc') ?: $xml->xpath('//url/loc');
        if (!empty($urlNodes)) {
            foreach ($urlNodes as $node) {
                $urls[] = (string) $node;
            }
        }

        return $urls;
    }

    /**
     * Parse an HTML sitemap page and extract URLs.
     *
     * Extracts all anchor hrefs from the HTML content, filtering out
     * non-HTTP links (javascript:, mailto:, tel:, fragments).
     *
     * @param  string  $content  The HTML content.
     * @param  string  $baseUrl  The base URL for resolving relative links.
     * @return array<string> Array of discovered page URLs.
     */
    public function parseHtmlSitemap(string $content, string $baseUrl): array
    {
        $urls = [];

        try {
            $crawler = new Crawler($content, $baseUrl);

            // Extract all links from the HTML sitemap page
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls, $baseUrl) {
                $href = $node->attr('href');

                if ($href === null || $href === '') {
                    return;
                }

                // Skip non-http links
                if (preg_match('/^(javascript|mailto|tel|#)/', $href)) {
                    return;
                }

                $normalizedUrl = $this->normalizeUrl($href, $baseUrl);

                if ($normalizedUrl !== null) {
                    $urls[] = $normalizedUrl;
                }
            });
        } catch (\Exception $e) {
            // Silently handle parsing errors
        }

        return $urls;
    }

    /**
     * Parse a plain text sitemap (one URL per line).
     *
     * Parses content where each line contains a URL. Skips empty lines
     * and lines starting with # (comments).
     *
     * @param  string  $content  The plain text content.
     * @return array<string> Array of discovered page URLs.
     */
    public function parseTextSitemap(string $content): array
    {
        $urls = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Check if it looks like a URL
            if (filter_var($line, FILTER_VALIDATE_URL)) {
                $urls[] = $line;
            }
        }

        return $urls;
    }

    /**
     * Normalize a URL relative to a base URL.
     *
     * Handles protocol-relative URLs, absolute paths, and relative paths.
     * Removes URL fragments.
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
}

