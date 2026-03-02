<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the ScannerService.
 *
 * Provides static access to URL processing (internal/external).
 *
 * @method static \App\Services\ScannerService setClient(\GuzzleHttp\Client $client) Set the HTTP client instance.
 * @method static \App\Services\ScannerService setBrowsershotFetcher(\App\Services\BrowsershotFetcher|null $fetcher) Set the BrowsershotFetcher for JavaScript rendering.
 * @method static array processInternalUrl(string $url, string $source, string $element = 'a') Process an internal URL and return scan result.
 * @method static array processExternalUrl(string $url, string $source, string $element = 'a') Process an external URL and return scan result.
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
