<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the ScannerService.
 *
 * Provides static access to website scanning and link checking functionality.
 *
 * @method static \App\Services\ScannerService setClient(\GuzzleHttp\Client $client) Set the HTTP client instance.
 * @method static \App\Services\ScannerService setMaxRedirects(int $maxRedirects) Set the maximum number of redirects to follow.
 * @method static \App\Services\ScannerService setBaseUrl(string $baseUrl) Set the base URL for scanning.
 * @method static string getBaseUrl() Get the current base URL.
 * @method static string getBaseHost() Get the current base host.
 * @method static array checkUrl(string $url, string $method = 'GET') Check the status of a URL by following redirects.
 * @method static array followRedirects(string $url, string $method = 'GET') Follow redirects for a given URL.
 * @method static array extractLinks(string $html, string $sourceUrl) Extract all links from HTML content.
 * @method static string|null normalizeUrl(?string $url, string $baseUrl) Normalize a URL relative to a base URL.
 * @method static bool isInternalUrl(string $url) Check if a URL is internal to the base host.
 * @method static array processInternalUrl(string $url, string $source) Process an internal URL and return scan result.
 * @method static array processExternalUrl(string $url, string $source) Process an external URL and return scan result.
 * @method static array calculateStats(array $results) Calculate statistics from scan results.
 * @method static array filterResults(array $results, string $filter) Filter scan results by status.
 *
 * @see \App\Services\ScannerService
 */
class Scanner extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'scanner';
    }
}

