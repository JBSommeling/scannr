<?php

namespace Scannr\Enums;

/**
 * Confidence level in the link analysis result.
 *
 * Indicates how certain we are about the link status.
 */
enum Confidence: string
{
    /** High confidence — direct observation, clear result. */
    case HIGH = 'high';

    /** Medium confidence — some uncertainty. */
    case MEDIUM = 'medium';

    /** Low confidence — likely false positive or needs manual verification. */
    case LOW = 'low';
}
