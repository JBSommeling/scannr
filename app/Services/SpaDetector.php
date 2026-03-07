<?php

namespace App\Services;

/**
 * Detects Single Page Application (SPA) signals in HTML content.
 *
 * Analyses raw HTML to determine whether a page is a client-side rendered
 * application that requires JavaScript rendering (headless browser) to
 * extract meaningful links and content.
 *
 * Detection strategies:
 * - No navigable <a> links extracted from static HTML
 * - Empty or near-empty <body> (typical SPA shell with a single mount-point div)
 * - Client-side routing / SPA framework markers (Next.js, Nuxt.js, React, Vue, Angular, Gatsby, etc.)
 */
class SpaDetector
{
    /**
     * Minimum text content length (in characters) for the <body> to be
     * considered "not empty". Below this threshold the page is treated
     * as an SPA shell.
     */
    protected int $emptyBodyThreshold = 50;

    /**
     * Detect SPA signals in raw HTML and extracted links.
     *
     * @param string|null $rawBody        The raw HTML body (before any JS rendering).
     * @param array        $extractedLinks The links extracted from the static HTML.
     * @return array{detected: bool, reason: string}
     */
    public function detect(?string $rawBody, array $extractedLinks): array
    {
        // Check 1: No navigable <a> links found at all
        $anchorLinks = array_filter(
            $extractedLinks,
            fn ($link) => ($link['element'] ?? '') === 'a',
        );

        if (empty($anchorLinks)) {
            return ['detected' => true, 'reason' => 'no navigable links found'];
        }

        if ($rawBody === null || trim($rawBody) === '') {
            return ['detected' => true, 'reason' => 'empty response body'];
        }

        // Check 2: Empty or near-empty <body>
        if ($this->isBodyEffectivelyEmpty($rawBody)) {
            return ['detected' => true, 'reason' => 'empty DOM body (SPA shell detected)'];
        }

        // Check 3: Client-side routing / SPA framework markers
        $frameworkReason = $this->detectFrameworkMarkers($rawBody);
        if ($frameworkReason !== null) {
            return ['detected' => true, 'reason' => $frameworkReason];
        }

        return ['detected' => false, 'reason' => ''];
    }

    /**
     * Check if the <body> is effectively empty (typical SPA shell).
     *
     * SPA shells typically have a body with just a single mount-point div
     * like <div id="root"></div> or <div id="app"></div> with no real
     * text content.
     */
    public function isBodyEffectivelyEmpty(string $html): bool
    {
        if (!preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $matches)) {
            return false;
        }

        $bodyContent = $matches[1];

        // Strip script, style, and noscript tags and their content
        $stripped = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $bodyContent);
        $stripped = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $stripped);
        $stripped = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/si', '', $stripped);

        // Strip all remaining HTML tags
        $textContent = trim(strip_tags($stripped));

        return strlen($textContent) < $this->emptyBodyThreshold;
    }

    /**
     * Detect SPA framework markers in the HTML source.
     *
     * @return string|null The detection reason, or null if no markers found.
     */
    public function detectFrameworkMarkers(string $html): ?string
    {
        // Next.js
        if (str_contains($html, '__NEXT_DATA__') || str_contains($html, '/_next/')) {
            return 'Next.js application detected';
        }

        // Nuxt.js
        if (str_contains($html, '__NUXT__') || str_contains($html, '/_nuxt/')) {
            return 'Nuxt.js application detected';
        }

        // React (Create React App or generic)
        if (preg_match('/<div\s+id=["\']root["\']\s*>\s*<\/div>/i', $html)
            && preg_match('/<script\b/i', $html)) {
            return 'React application detected (empty root mount point)';
        }

        // Vue.js
        if (preg_match('/<div\s+id=["\']app["\']\s*>\s*<\/div>/i', $html)
            && preg_match('/<script\b/i', $html)) {
            return 'Vue.js application detected (empty app mount point)';
        }

        // Angular
        if (preg_match('/\bng-version\s*=\s*["\']/', $html)
            || preg_match('/<app-root\b[^>]*>\s*<\/app-root>/i', $html)) {
            return 'Angular application detected';
        }

        // Gatsby
        if (str_contains($html, '___gatsby')) {
            return 'Gatsby application detected';
        }

        // Generic SPA: data-server-rendered (SSR hydration marker for Vue/Nuxt)
        if (str_contains($html, 'data-server-rendered')) {
            return 'server-rendered SPA detected (hydration marker)';
        }

        // Generic SPA: single mount point with data-reactroot
        if (str_contains($html, 'data-reactroot')) {
            return 'React application detected (data-reactroot)';
        }

        return null;
    }
}

