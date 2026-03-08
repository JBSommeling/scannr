<?php

namespace App\Services;

use App\Enums\Confidence;
use App\Enums\LinkFlag;
use App\Enums\Severity;

/**
 * Service for evaluating severity and confidence from link flags.
 *
 * Centralizes the logic for deriving severity, confidence, and verification
 * recommendations based on flag combinations. Flags describe observations;
 * this service determines what those observations mean.
 */
class SeverityEvaluator
{
    /**
     * Evaluate severity based on flags.
     *
     * Priority:
     * 1. FORM_ENDPOINT with healthy 4xx (400,401,403,405,422,429) → INFO (expected POST rejections)
     * 2. STATUS_4XX on internal (non-platform) URL → CRITICAL (including form 404)
     * 3. STATUS_5XX → CRITICAL
     * 4. CONNECTION_ERROR → CRITICAL
     * 5. MALFORMED_URL → WARNING
     * 6. BOT_PROTECTION → WARNING
     * 7. STATUS_4XX on external platform → WARNING
     * 8. TIMEOUT → WARNING
     * 9. HTTP_ON_HTTPS → WARNING
     * 10. EXCESSIVE_REDIRECTS → WARNING
     * 11. Everything else → INFO
     *
     * @param  array<LinkFlag>  $flags
     * @param  int|string  $status  HTTP status code or error string
     */
    public function evaluate(array $flags, int|string $status = 0): Severity
    {
        $hasExternalPlatform = in_array(LinkFlag::EXTERNAL_PLATFORM, $flags, true);
        $hasStatus4xx = in_array(LinkFlag::STATUS_4XX, $flags, true);
        $hasStatus5xx = in_array(LinkFlag::STATUS_5XX, $flags, true);
        $hasConnectionError = in_array(LinkFlag::CONNECTION_ERROR, $flags, true);
        $hasMalformedUrl = in_array(LinkFlag::MALFORMED_URL, $flags, true);
        $hasBotProtection = in_array(LinkFlag::BOT_PROTECTION, $flags, true);
        $hasTimeout = in_array(LinkFlag::TIMEOUT, $flags, true);
        $hasHttpOnHttps = in_array(LinkFlag::HTTP_ON_HTTPS, $flags, true);
        $hasExcessiveRedirects = in_array(LinkFlag::EXCESSIVE_REDIRECTS, $flags, true);
        $hasFormEndpoint = in_array(LinkFlag::FORM_ENDPOINT, $flags, true);

        // Form endpoints returning certain 4xx are expected behavior:
        // 400 (bad request / missing data), 401 (auth required), 403 (forbidden),
        // 405 (method not allowed), 422 (validation error), 429 (rate limited).
        // 404 is NOT healthy — it means the endpoint doesn't exist.
        $healthyFormStatuses = [400, 401, 403, 405, 422, 429];
        $statusInt = is_int($status) ? $status : 0;

        if ($hasFormEndpoint && $hasStatus4xx && in_array($statusInt, $healthyFormStatuses, true)) {
            return Severity::INFO;
        }

        // Critical: Developer leftover (localhost/dev URLs should never be in production)
        $hasDeveloperLeftover = in_array(LinkFlag::DEVELOPER_LEFTOVER, $flags, true);
        if ($hasDeveloperLeftover) {
            return Severity::CRITICAL;
        }

        // Critical: Internal 4xx, 5xx, or connection errors
        if ($hasStatus4xx && ! $hasExternalPlatform && ! $hasBotProtection) {
            return Severity::CRITICAL;
        }

        if ($hasStatus5xx) {
            return Severity::CRITICAL;
        }

        if ($hasConnectionError && ! $hasExternalPlatform) {
            return Severity::CRITICAL;
        }

        // Warning: Various issues that need attention but may be false positives
        if ($hasMalformedUrl) {
            return Severity::WARNING;
        }

        if ($hasBotProtection) {
            return Severity::WARNING;
        }

        if ($hasStatus4xx && $hasExternalPlatform) {
            return Severity::WARNING;
        }

        if ($hasTimeout) {
            return Severity::WARNING;
        }

        if ($hasHttpOnHttps) {
            return Severity::WARNING;
        }

        if ($hasExcessiveRedirects) {
            return Severity::WARNING;
        }

        return Severity::INFO;
    }

    /**
     * Evaluate confidence level based on flags and context.
     *
     * High confidence:
     * - Direct 2xx response
     * - Internal 404 (clear broken link)
     *
     * Medium confidence:
     * - Timeout (might be temporary)
     * - Redirect chains (might be intentional)
     * - 5xx errors (server might be temporarily down)
     *
     * Low confidence:
     * - External platform with bot protection (likely false positive)
     * - JS bundle extracted URLs (might not be real links)
     * - Malformed URLs (might be template literals)
     *
     * @param  array<LinkFlag>  $flags
     * @param  int|string  $status  HTTP status or error string
     * @param  bool  $isExternal  Whether the URL is external
     */
    public function evaluateConfidence(array $flags, int|string $status, bool $isExternal): Confidence
    {
        $hasExternalPlatform = in_array(LinkFlag::EXTERNAL_PLATFORM, $flags, true);
        $hasBotProtection = in_array(LinkFlag::BOT_PROTECTION, $flags, true);
        $hasJsBundleExtracted = in_array(LinkFlag::DETECTED_IN_JS_BUNDLE, $flags, true);
        $hasMalformedUrl = in_array(LinkFlag::MALFORMED_URL, $flags, true);
        $hasIndirectReference = in_array(LinkFlag::INDIRECT_REFERENCE, $flags, true);
        $hasTimeout = in_array(LinkFlag::TIMEOUT, $flags, true);
        $hasStatus5xx = in_array(LinkFlag::STATUS_5XX, $flags, true);
        $hasRedirectChain = in_array(LinkFlag::REDIRECT_CHAIN, $flags, true);

        // Low confidence: likely false positives
        if ($hasExternalPlatform && $hasBotProtection) {
            return Confidence::LOW;
        }

        if ($hasMalformedUrl || $hasIndirectReference) {
            return Confidence::LOW;
        }

        if ($hasJsBundleExtracted && $isExternal) {
            return Confidence::LOW;
        }

        // Medium confidence: uncertain situations
        if ($hasTimeout) {
            return Confidence::MEDIUM;
        }

        if ($hasStatus5xx) {
            return Confidence::MEDIUM;
        }

        if ($hasRedirectChain) {
            return Confidence::MEDIUM;
        }

        if ($hasJsBundleExtracted) {
            return Confidence::MEDIUM;
        }

        // High confidence: clear results
        return Confidence::HIGH;
    }

    /**
     * Evaluate verification recommendation based on severity and confidence.
     *
     * - 'recommended': Low confidence issues that need manual verification
     * - 'optional': Medium confidence or non-critical issues
     * - 'none': High confidence, no verification needed
     *
     * @return string 'recommended' | 'optional' | 'none'
     */
    public function evaluateVerification(Severity $severity, Confidence $confidence): string
    {
        // Low confidence always recommends verification
        if ($confidence === Confidence::LOW) {
            return 'recommended';
        }

        // Critical issues with high confidence don't need verification (clearly broken)
        if ($severity === Severity::CRITICAL && $confidence === Confidence::HIGH) {
            return 'none';
        }

        // Medium confidence or warnings suggest optional verification
        if ($confidence === Confidence::MEDIUM) {
            return 'optional';
        }

        if ($severity === Severity::WARNING) {
            return 'optional';
        }

        return 'none';
    }
}
