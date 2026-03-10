<?php

namespace App\Enums;

/**
 * Penalty issue types resolved by the integrity scorer.
 *
 * Each case represents a single penalty category that can be applied to a
 * scan result. Unlike LinkFlag (which captures raw, combinable observations),
 * IssueType is the resolved outcome of evaluating flags, link type, and
 * HTTP status together — at most one IssueType per result.
 */
enum IssueType: string
{
    /** URL points to localhost or development environment. */
    case DEVELOPER_LEFTOVER = 'developer_leftover';

    /** HTTP 4xx error on an internal link. */
    case STATUS_4XX_INTERNAL = 'status_4xx_internal';

    /** HTTP 5xx server error. */
    case STATUS_5XX = 'status_5xx';

    /** DNS or connection failure. */
    case CONNECTION_ERROR = 'connection_error';

    /** Form endpoint returning 404 (genuinely missing). */
    case FORM_ENDPOINT_404 = 'form_endpoint_404';

    /** HTTP 4xx on an external platform without bot protection. */
    case STATUS_4XX_EXTERNAL_PLATFORM = 'status_4xx_external_platform';

    /** URL contains template literals, placeholders, or malformed syntax. */
    case MALFORMED_URL = 'malformed_url';

    /** 5+ redirect hops or redirect loop detected. */
    case EXCESSIVE_REDIRECTS = 'excessive_redirects';

    /** HTTPS to HTTP downgrade detected in redirect chain. */
    case HTTP_ON_HTTPS = 'http_on_https';

    /** Request timed out. */
    case TIMEOUT = 'timeout';

    /** URL has a redirect chain (2+ hops). */
    case REDIRECT_CHAIN = 'redirect_chain';

    /** Response indicates bot protection (403/405 from known platform). */
    case BOT_PROTECTION = 'bot_protection';

    /** 429 Too Many Requests received. */
    case RATE_LIMITED = 'rate_limited';
}
