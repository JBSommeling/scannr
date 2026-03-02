<?php

namespace App\Services;

/**
 * Service for normalizing, resolving, and classifying URLs.
 *
 * Handles URL normalization (relative to absolute), canonical key generation,
 * tracking parameter stripping, and internal/external URL classification.
 */
class UrlNormalizer
{
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
     * Create a new UrlNormalizer instance.
     */
    public function __construct()
    {
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
    public function stripTrackingParams(string $url): string
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
    public function isTrackingParam(string $paramName): bool
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
}

