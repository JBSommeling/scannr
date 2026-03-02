<?php

namespace App\Enums;

enum VerificationReason: string
{
    /**
     * Request returned 403/405 or network error — likely blocked by bot protection.
     */
    case BotProtection = 'bot_protection';

    /**
     * URL contains incomplete template literals or dynamic syntax (e.g. `${r}`, `{id}`).
     * The URL is an indirect reference and may not be a real link.
     */
    case IndirectReference = 'indirect_reference';

    /**
     * URL points to localhost or 127.0.0.1 — developer leftover, not a real link.
     */
    case DeveloperLeftover = 'developer_leftover';

    /**
     * URL was extracted from a JS bundle and needs manual verification.
     */
    case JsBundleExtracted = 'js_bundle_extracted';
}

