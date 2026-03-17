<?php

namespace Scannr\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the HttpChecker service.
 *
 * Provides static access to URL health checking and redirect following.
 *
 * @method static \Scannr\Services\HttpChecker setClient(\GuzzleHttp\Client $client) Set the HTTP client instance.
 * @method static \Scannr\Services\HttpChecker setMaxRedirects(int $maxRedirects) Set the maximum number of redirects to follow.
 * @method static array checkUrl(string $url, string $method = 'GET') Check the status of a URL by following redirects.
 * @method static array followRedirects(string $url, string $method = 'GET') Follow redirects for a given URL.
 * @method static bool isWwwOnlyRedirect(string $fromUrl, string $toUrl) Check if a redirect is only a www normalization.
 * @method static string|null fetchScriptContent(string $url) Fetch the content of an external script file.
 * @method static array processFormEndpoint(string $url, string $source, string $type = 'internal') Process a form submission endpoint.
 *
 * @see \Scannr\Services\HttpChecker
 */
class Http extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'http-checker';
    }
}
