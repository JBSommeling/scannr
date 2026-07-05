<?php

namespace Scannr\Services\Concerns;

/**
 * Shared Content-Type sniffing logic for deciding whether a response body is
 * plausibly HTML (and therefore safe/worthwhile to parse for links).
 */
trait DetectsHtmlContentType
{
    /**
     * Determine whether a Content-Type header is plausibly HTML, or absent.
     *
     * A missing/empty Content-Type is treated permissively (returns true) so
     * that genuine HTML served without the header is still read; the
     * LinkExtractor binary sniff is the belt-and-suspenders layer that
     * catches non-HTML bodies in that case. A declared, non-HTML content
     * type (e.g. image/gif) returns false so binary bodies are never
     * materialized in memory.
     *
     * Guzzle joins duplicate headers with getHeaderLine() as a
     * comma-separated string (e.g. "text/html, text/html" for a response
     * that sent the Content-Type header twice with the same value), so only
     * the first comma-separated value is considered before extracting the
     * media type from any semicolon parameters.
     *
     * @param  string|null  $contentType  The raw Content-Type header value, or null if absent.
     */
    protected function isHtmlOrUnknownContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return true;
        }

        $first = explode(',', $contentType)[0];
        $mimeType = strtolower(trim(explode(';', $first)[0]));

        return $mimeType === 'text/html' || $mimeType === 'application/xhtml+xml';
    }
}
