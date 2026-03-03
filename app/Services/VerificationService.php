<?php

namespace App\Services;

use App\DTO\VerificationStatus;
use App\Enums\VerificationReason;

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
     * Returns multiple reasons when applicable:
     * - JsBundleExtracted is always included for external URLs
     * - IndirectReference is added if URL has suspicious syntax
     * - DeveloperLeftover is added if URL points to loopback address
     * - Internal URLs without issues return None
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

        $reasons = [];

        // External URLs always get JsBundleExtracted
        if (!$isInternal) {
            $reasons[] = VerificationReason::JsBundleExtracted;
        }

        // Add IndirectReference if suspicious syntax
        if ($hasSuspiciousSyntax) {
            $reasons[] = VerificationReason::IndirectReference;
        }

        // Add DeveloperLeftover if loopback
        if ($this->isLoopbackUrl($url)) {
            $reasons[] = VerificationReason::DeveloperLeftover;
        }

        return VerificationStatus::fromReasons($reasons);
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

