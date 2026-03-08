<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for checking URL health via HTTP requests.
 *
 * Handles following redirects, detecting redirect loops,
 * HTTPS downgrades, form endpoint validation, and fetching script content.
 */
class HttpChecker
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
     * Create a new HttpChecker instance.
     *
     * @param  UrlNormalizer  $urlNormalizer  The URL normalizer for resolving redirect URLs.
     * @param  LinkFlagService  $linkFlagService  The link flag service for detecting flags.
     * @param  Client|null  $client  Optional Guzzle HTTP client instance.
     */
    public function __construct(
        protected UrlNormalizer $urlNormalizer,
        protected LinkFlagService $linkFlagService,
        ?Client $client = null,
    ) {
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
     * Check the status of a URL by following redirects.
     *
     * Performs an HTTP request and follows any redirect chains up to the
     * configured maximum. Returns detailed information about the final
     * response and any redirects encountered.
     *
     * @param  string  $url  The URL to check.
     * @param  string  $method  HTTP method to use ('GET' or 'HEAD'). Default: 'GET'.
     * @return array{
     *     finalStatus: int|string,
     *     chain: array<string>,
     *     loop: bool,
     *     body: string|null,
     *     hasHttpsDowngrade: bool
     * }
     *
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
     * @param  string  $url  The URL to request.
     * @param  string  $method  HTTP method ('GET' or 'HEAD').
     * @return array{
     *     finalStatus: int|string,
     *     finalUrl: string,
     *     chain: array<string>,
     *     loop: bool,
     *     body: string|null,
     *     hasHttpsDowngrade: bool,
     *     retryAfter: int|null
     * }
     *
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
        $retryAfter = null;

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
                    $location = $this->urlNormalizer->resolveRedirectUrl($rawLocation, $currentUrl);

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
                        $chain[] = $location.' (LOOP)';
                        break;
                    }

                    $visitedUrls[$location] = true;

                    // Skip www-only redirects from the chain (but still follow them)
                    if (! $this->isWwwOnlyRedirect($currentUrl, $location)) {
                        $chain[] = $location;
                        $hops++;
                    }

                    $currentUrl = $location;

                    continue;
                }

                // Extract Retry-After header for 429 responses (in seconds)
                if ($finalStatus === 429) {
                    $retryAfterHeader = $response->getHeaderLine('Retry-After');
                    if (! empty($retryAfterHeader) && is_numeric($retryAfterHeader)) {
                        $retryAfter = (int) $retryAfterHeader;
                    }
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
            'retryAfter' => $retryAfter,
        ];
    }

    /**
     * Check if a redirect is only a www normalization (www to non-www or vice versa).
     *
     * @param  string  $fromUrl  The original URL.
     * @param  string  $toUrl  The redirect target URL.
     * @return bool True if the redirect only changes the www prefix.
     */
    public function isWwwOnlyRedirect(string $fromUrl, string $toUrl): bool
    {
        $fromParsed = parse_url($fromUrl);
        $toParsed = parse_url($toUrl);

        if (! isset($fromParsed['host']) || ! isset($toParsed['host'])) {
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
        $fromWithoutHost = ($fromParsed['scheme'] ?? '').'://'.
                          ($fromParsed['path'] ?? '/').
                          (isset($fromParsed['query']) ? '?'.$fromParsed['query'] : '');
        $toWithoutHost = ($toParsed['scheme'] ?? '').'://'.
                        ($toParsed['path'] ?? '/').
                        (isset($toParsed['query']) ? '?'.$toParsed['query'] : '');

        // Same scheme, path, and query = www-only redirect
        return $fromWithoutHost === $toWithoutHost;
    }

    /**
     * Fetch the content of an external script file.
     *
     * Makes a GET request to retrieve the JavaScript bundle content.
     * Used when --js is enabled to scan external bundles for download URLs.
     *
     * @param  string  $url  The absolute URL of the script file.
     * @return string|null The script content, or null on failure.
     */
    public function fetchScriptContent(string $url): ?string
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
     * Process a form submission endpoint URL and return scan result.
     *
     * Sends a POST request with an empty JSON body to check if the endpoint
     * is alive. Form endpoints typically only accept POST, so GET/HEAD
     * would return 405 Method Not Allowed.
     *
     * The following responses are considered "healthy" (endpoint exists and works):
     * - 200-299: Success
     * - 400: Bad Request — endpoint exists, needs proper data
     * - 401/403: Auth required — endpoint exists, needs credentials
     * - 405: Method Not Allowed — endpoint exists (may need different content-type)
     * - 422: Unprocessable Entity — endpoint exists, validation rejected empty data
     * - 429: Rate limited — endpoint exists, too many requests
     *
     * Only 404, 500+, timeouts, and connection errors indicate a truly broken endpoint.
     *
     * @param  string  $url  The form endpoint URL.
     * @param  string  $source  The source page where this URL was found.
     * @param  string  $type  'internal' or 'external'.
     * @return array The scan result array.
     */
    public function processFormEndpoint(string $url, string $source, string $type = 'internal'): array
    {
        $status = 0;
        $retryAfter = null;

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => '{}',
            ]);
            $status = $response->getStatusCode();
        } catch (ConnectException $e) {
            if (str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout')) {
                $status = 'Timeout';
            } else {
                $status = 'Error';
            }
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $status = $e->getResponse()->getStatusCode();
                if ($status === 429) {
                    $retryAfterHeader = $e->getResponse()->getHeaderLine('Retry-After');
                    if (! empty($retryAfterHeader) && is_numeric($retryAfterHeader)) {
                        $retryAfter = (int) $retryAfterHeader;
                    }
                }
            } else {
                $status = 'Error';
            }
        } catch (\Exception $e) {
            $status = 'Error';
        }

        $isExternal = $type === 'external';

        // Collect flags
        $flags = $this->linkFlagService->detectFormEndpoint();
        $flags = array_merge($flags, $this->linkFlagService->detectFromHttpResponse($status));

        if ($isExternal) {
            $flags = array_merge($flags, $this->linkFlagService->detectFromUrl($url, true));
        }

        // Build analysis
        $analysis = $this->linkFlagService->buildAnalysis($flags, $status, $isExternal);

        // Format status string
        $statusString = is_int($status) ? (string) $status : strtolower($status);

        return [
            'url' => $url,
            'finalUrl' => $url,
            'sourcePage' => $source,
            'status' => $statusString,
            'type' => $type,
            'sourceElement' => 'form',
            'extractedLinks' => [],
            'analysis' => $analysis->toArray(),
            'redirect' => [
                'chain' => [],
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
            ],
            'network' => [
                'retryAfter' => $retryAfter,
            ],
        ];
    }
}
