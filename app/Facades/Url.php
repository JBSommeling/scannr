<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the UrlNormalizer service.
 *
 * Provides static access to URL normalization, canonicalization, and classification.
 *
 * @method static \App\Services\UrlNormalizer setBaseUrl(string $baseUrl) Set the base URL for scanning.
 * @method static string getBaseUrl() Get the current base URL.
 * @method static string getBaseHost() Get the current base host.
 * @method static \App\Services\UrlNormalizer setTrackingParams(array $params) Set the tracking parameters to strip from URLs.
 * @method static \App\Services\UrlNormalizer addTrackingParams(array $params) Add additional tracking parameters to strip from URLs.
 * @method static array getTrackingParams() Get the current tracking parameters.
 * @method static string|null normalizeUrl(?string $url, string $baseUrl) Normalize a URL relative to a base URL.
 * @method static string canonicalUrlKey(string $url) Generate a canonical key for a URL.
 * @method static string|null resolveRedirectUrl(?string $url, string $baseUrl) Resolve a redirect URL to an absolute URL.
 * @method static string stripTrackingParams(string $url) Strip tracking parameters from a URL.
 * @method static bool isTrackingParam(string $paramName) Check if a parameter name matches a tracking parameter pattern.
 * @method static bool isInternalUrl(string $url) Check if a URL is internal to the base host.
 *
 * @see \App\Services\UrlNormalizer
 */
class Url extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'url-normalizer';
    }
}

