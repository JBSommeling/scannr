<?php

namespace App\Services;

use App\DTO\LinkAnalysis;
use App\Enums\Confidence;
use App\Enums\LinkFlag;
use App\Enums\Severity;

/**
 * Service for detecting link flags based on various observations.
 *
 * Centralizes all flag detection logic. Flags describe observations;
 * severity/confidence are evaluated separately by SeverityEvaluator.
 */
class LinkFlagService
{
    public function __construct(
        protected UrlNormalizer $urlNormalizer,
        protected SeverityEvaluator $severityEvaluator,
    ) {}

    /**
     * Build a complete LinkAnalysis from collected flags.
     *
     * @param array<LinkFlag> $flags
     * @param int|string $status HTTP status or error string
     * @param bool $isExternal Whether the URL is external
     */
    public function buildAnalysis(array $flags, int|string $status, bool $isExternal): LinkAnalysis
    {
        $uniqueFlags = array_values(array_unique($flags, SORT_REGULAR));
        $severity = $this->severityEvaluator->evaluate($uniqueFlags);
        $confidence = $this->severityEvaluator->evaluateConfidence($uniqueFlags, $status, $isExternal);
        $verification = $this->severityEvaluator->evaluateVerification($severity, $confidence);

        return LinkAnalysis::create($uniqueFlags, $severity, $confidence, $verification);
    }

    /**
     * Detect flags from HTTP response status.
     *
     * @param int|string $status The HTTP status code or error string.
     * @return array<LinkFlag>
     */
    public function detectFromHttpResponse(int|string $status): array
    {
        $flags = [];

        if ($status === 'Timeout') {
            $flags[] = LinkFlag::TIMEOUT;
            return $flags;
        }

        if ($status === 'Error') {
            $flags[] = LinkFlag::CONNECTION_ERROR;
            return $flags;
        }

        if (!is_int($status)) {
            $flags[] = LinkFlag::UNVERIFIED;
            return $flags;
        }

        // Bot protection indicators
        if (in_array($status, [403, 405], true)) {
            $flags[] = LinkFlag::BOT_PROTECTION;
        }

        // Rate limiting
        if ($status === 429) {
            $flags[] = LinkFlag::RATE_LIMITED;
        }

        // 4xx client errors
        if ($status >= 400 && $status < 500) {
            $flags[] = LinkFlag::STATUS_4XX;
        }

        // 5xx server errors
        if ($status >= 500 && $status < 600) {
            $flags[] = LinkFlag::STATUS_5XX;
        }

        return $flags;
    }

    /**
     * Detect flags from URL discovery method.
     *
     * @param bool $isJsRendered Whether the page was rendered with JS (headless browser)
     * @param bool $fromJsBundle Whether the URL was extracted from a JS bundle
     * @param bool $hasSuspiciousSyntax Whether the URL has suspicious dynamic syntax
     * @return array<LinkFlag>
     */
    public function detectFromDiscovery(
        bool $isJsRendered = false,
        bool $fromJsBundle = false,
        bool $hasSuspiciousSyntax = false,
    ): array {
        $flags = [];

        if ($fromJsBundle) {
            $flags[] = LinkFlag::DETECTED_IN_JS_BUNDLE;
        } elseif ($isJsRendered) {
            $flags[] = LinkFlag::RUNTIME_RENDERED;
        } else {
            $flags[] = LinkFlag::STATIC_HTML;
        }

        if ($hasSuspiciousSyntax) {
            $flags[] = LinkFlag::MALFORMED_URL;
            $flags[] = LinkFlag::INDIRECT_REFERENCE;
        }

        return $flags;
    }

    /**
     * Detect flags from URL characteristics.
     *
     * @param string $url The URL to analyze
     * @param bool $isExternal Whether the URL is external
     * @return array<LinkFlag>
     */
    public function detectFromUrl(string $url, bool $isExternal): array
    {
        $flags = [];

        // Check for external platform
        if ($isExternal && $this->isExternalPlatform($url)) {
            $flags[] = LinkFlag::EXTERNAL_PLATFORM;
        }

        // Check for localhost/development URLs
        if ($this->isLocalhostUrl($url)) {
            $flags[] = LinkFlag::LOCALHOST_URL;
        }

        // Check for malformed URL syntax
        if ($this->hasMalformedSyntax($url)) {
            $flags[] = LinkFlag::MALFORMED_URL;
            $flags[] = LinkFlag::INDIRECT_REFERENCE;
        }

        return $flags;
    }

    /**
     * Detect flags from redirect chain analysis.
     *
     * @param array<string> $chain The redirect chain
     * @param bool $hasLoop Whether a redirect loop was detected
     * @param bool $hasHttpsDowngrade Whether HTTPS to HTTP downgrade occurred
     * @return array<LinkFlag>
     */
    public function detectFromRedirect(array $chain, bool $hasLoop, bool $hasHttpsDowngrade): array
    {
        $flags = [];

        $hopCount = count($chain);

        if ($hopCount >= 2) {
            $flags[] = LinkFlag::REDIRECT_CHAIN;
        }

        if ($hopCount >= 5 || $hasLoop) {
            $flags[] = LinkFlag::EXCESSIVE_REDIRECTS;
        }

        if ($hasHttpsDowngrade) {
            $flags[] = LinkFlag::HTTP_ON_HTTPS;
        }

        return $flags;
    }

    /**
     * Detect flags for form endpoints.
     *
     * @return array<LinkFlag>
     */
    public function detectFormEndpoint(): array
    {
        return [LinkFlag::FORM_ENDPOINT];
    }

    /**
     * Check if a URL belongs to a known external platform.
     */
    protected function isExternalPlatform(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return false;
        }

        $host = strtolower($host);
        $platforms = $this->getExternalPlatforms();

        foreach ($platforms as $platform) {
            // Match exact domain or subdomain (e.g., www.linkedin.com matches linkedin.com)
            if ($host === $platform || str_ends_with($host, '.' . $platform)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL has malformed syntax (template literals, placeholders, etc.).
     */
    protected function hasMalformedSyntax(string $url): bool
    {
        // Check for template literal syntax with variable names, backticks, etc.
        // Note: \{[a-zA-Z] requires a letter after the brace to avoid false positives
        return (bool) preg_match('/\$\{|\#\{|\{[a-zA-Z]|\}[a-zA-Z]|`|,\w+$|"\s*,|\n/', $url);
    }

    /**
     * Get list of external platforms from config.
     *
     * @return array<string>
     */
    protected function getExternalPlatforms(): array
    {
        $defaults = [
            'linkedin.com',
            'github.com',
            'twitter.com',
            'x.com',
            'facebook.com',
            'instagram.com',
            'tiktok.com',
            'youtube.com',
            'medium.com',
            'reddit.com',
            'pinterest.com',
            'tumblr.com',
            'snapchat.com',
            'discord.com',
            'slack.com',
            'whatsapp.com',
            'telegram.org',
            'twitch.tv',
            'vimeo.com',
            'dribbble.com',
            'behance.net',
            'codepen.io',
            'stackoverflow.com',
            'producthunt.com',
        ];

        try {
            return config('scanner.external_platforms', $defaults) ?? $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * Check if a URL points to a loopback address.
     */
    public function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /**
     * Check if a URL points to localhost or a development environment.
     *
     * Detects:
     * - localhost, 127.0.0.1, ::1, 0.0.0.0
     * - .local, .test, .localhost, .invalid, .example TLDs (common dev domains)
     */
    public function isLocalhostUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return false;
        }

        $host = strtolower($host);

        // Remove IPv6 brackets if present
        $host = trim($host, '[]');

        // Check for localhost variants
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        // Check for .local, .test, .localhost, .invalid TLDs (common dev domains)
        // Note: .example is a reserved TLD, but example.com is a real domain
        if (preg_match('/\.(local|test|localhost|invalid)$/i', $host)) {
            return true;
        }

        return false;
    }

    /**
     * Check if verification should be cleared for a successful subdomain response.
     *
     * A bare internal subdomain that responds with 200 is proven alive;
     * analysis flags may need adjustment.
     */
    public function shouldClearForSubdomain(string $url, int|string $status): bool
    {
        return $status === 200 && $this->urlNormalizer->isSubdomainUrl($url);
    }
}

