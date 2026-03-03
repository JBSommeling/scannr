<?php

namespace App\Services;

use App\DTO\VerificationStatus;

/**
 * Service for determining URL verification requirements.
 *
 * Centralizes all logic for detecting when a URL needs manual verification
 * and why (bot protection, indirect reference, developer leftover, etc.).
 */
class VerificationService
{
    public function __construct(
        protected UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * Detect verification status from HTTP response status.
     *
     * Returns bot protection status for 403, 405, Error, or Timeout responses.
     *
     * @param int|string $status The HTTP status code or error string.
     */
    public function detectFromHttpResponse(int|string $status): VerificationStatus
    {
        if (in_array($status, [403, 405, 'Error', 'Timeout'], true)) {
            return VerificationStatus::forBotProtection();
        }

        return VerificationStatus::none();
    }

    /**
     * Detect verification status for a URL extracted from a JS bundle.
     *
     * Determines the appropriate verification reason based on:
     * - Suspicious syntax (template literals, dynamic patterns) → IndirectReference
     * - Loopback addresses (localhost, 127.0.0.1, ::1) → DeveloperLeftover
     * - External URLs from JS bundles → JsBundleExtracted
     * - Internal URLs without issues → None
     *
     * @param string $url The extracted URL.
     * @param bool $hasSuspiciousSyntax Whether the URL contains suspicious dynamic syntax.
     * @param bool $isInternal Whether the URL is internal to the scanned site.
     */
    public function detectFromJsBundle(string $url, bool $hasSuspiciousSyntax, bool $isInternal): VerificationStatus
    {
        // Internal URLs without suspicious syntax don't need verification
        if ($isInternal && !$hasSuspiciousSyntax) {
            return VerificationStatus::none();
        }

        // Determine the reason
        if ($hasSuspiciousSyntax) {
            return VerificationStatus::forIndirectReference();
        }

        if ($this->isLoopbackUrl($url)) {
            return VerificationStatus::forDeveloperLeftover();
        }

        // External URL from JS bundle
        return VerificationStatus::forJsBundleExtracted();
    }

    /**
     * Check if verification should be cleared for a successful subdomain response.
     *
     * A bare internal subdomain that responds with 200 is proven alive;
     * no manual verification is needed regardless of how it was flagged.
     *
     * @param string $url The URL that was checked.
     * @param int $status The HTTP status code received.
     */
    public function shouldClearForSubdomain(string $url, int $status): bool
    {
        return $status === 200 && $this->urlNormalizer->isSubdomainUrl($url);
    }

    /**
     * Check if a URL points to a loopback address.
     *
     * @param string $url The URL to check.
     */
    protected function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        // IPv6 addresses are returned with brackets by parse_url (e.g., "[::1]")
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}

