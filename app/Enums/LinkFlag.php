<?php

namespace App\Enums;

/**
 * Flags describing observations about a link.
 *
 * Flags are orthogonal (can be combined) and describe observations, not conclusions.
 * Severity and confidence are derived separately based on flag combinations.
 */
enum LinkFlag: string
{
    // ========================================
    // A. Discovery — How was the link found?
    // ========================================

    /** Direct in HTML markup (standard DOM extraction). */
    case STATIC_HTML = 'static_html';

    /** Discovered via JavaScript rendering (headless browser). */
    case RUNTIME_RENDERED = 'runtime_rendered';

    /** URL contains template patterns ({var}), path parameters, or extraction artifacts suggesting concatenation. */
    case INDIRECT_REFERENCE = 'indirect_reference';

    /** Extracted from inline or external JS bundle parsing. */
    case DETECTED_IN_JS_BUNDLE = 'detected_in_js_bundle';

    // ========================================
    // B. Platform Characteristics
    // ========================================

    /** External platform known for bot protection (github, linkedin, etc.). */
    case EXTERNAL_PLATFORM = 'external_platform';

    /** 403/405 or similar response indicating bot protection. */
    case BOT_PROTECTION = 'bot_protection';

    /** 429 Too Many Requests received. */
    case RATE_LIMITED = 'rate_limited';

    // ========================================
    // C. Technical Anomalies
    // ========================================

    /** URL contains template literal syntax (${var}, #{var}), backticks, or control characters that make it impossible to visit. */
    case MALFORMED_URL = 'malformed_url';

    /** URL points to localhost or development environment (localhost, 127.0.0.1, .local, .test). */
    case DEVELOPER_LEFTOVER = 'developer_leftover';

    /** HTTPS to HTTP downgrade detected in redirect chain. */
    case HTTP_ON_HTTPS = 'http_on_https';

    /** URL has a redirect chain (2+ hops). */
    case REDIRECT_CHAIN = 'redirect_chain';

    /** Excessive redirects (5+ hops or loop detected). */
    case EXCESSIVE_REDIRECTS = 'excessive_redirects';

    // ========================================
    // D. Validation Outcome
    // ========================================

    /** HTTP 4xx client error response. */
    case STATUS_4XX = 'status_4xx';

    /** HTTP 5xx server error response. */
    case STATUS_5XX = 'status_5xx';

    /** Request timed out. */
    case TIMEOUT = 'timeout';

    /** Connection error (DNS failure, refused, etc.). */
    case CONNECTION_ERROR = 'connection_error';

    /** Could not be verified, needs manual check. */
    case UNVERIFIED = 'unverified';

    // ========================================
    // E. Endpoint Type
    // ========================================

    /** Form submission endpoint (POST-based). */
    case FORM_ENDPOINT = 'form_endpoint';
}
