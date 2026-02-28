<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking Parameters
    |--------------------------------------------------------------------------
    |
    | These query parameters will be stripped from URLs during normalization.
    | Use '*' suffix for prefix matching (e.g., 'utm_*' matches 'utm_source',
    | 'utm_medium', etc.). Matching is case-insensitive.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | The User-Agent header sent with all HTTP requests. This identifies the
    | crawler to web servers. Using a transparent bot identifier is more
    | ethical than impersonating a regular browser.
    |
    */

    'user_agent' => 'ScannrBot/1.0 (+https://scannr.io)',

    'tracking_params' => [
        'utm_*',
        'fbclid',
        'gclid',
        'ref',
        'source',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Control the delay between HTTP requests to avoid overwhelming servers.
    | Values are in milliseconds. A random delay between min and max will be
    | applied between each request in the main crawl loop.
    |
    */

    'request_delay_min' => 100,
    'request_delay_max' => 300,

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for a response from the server.
    | This acts as a hard cap regardless of what is passed via command options.
    |
    */

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Hard Limits
    |--------------------------------------------------------------------------
    |
    | These are absolute maximum values that cannot be exceeded regardless
    | of what the user specifies via command line options. This protects
    | against excessive resource usage and accidental abuse.
    |
    */

    'hard_max_depth' => 10,
    'hard_max_urls' => 2000,

    /*
    |--------------------------------------------------------------------------
    | JavaScript Rendering
    |--------------------------------------------------------------------------
    |
    | Configuration for headless browser rendering (--js flag).
    | This uses Puppeteer via spatie/browsershot to render JavaScript
    | content, enabling scanning of SPAs (React, Vue, Angular, etc.).
    |
    | Requirements: Node.js + Puppeteer (npm install puppeteer)
    |
    */

    'js_rendering' => [
        // Path to node binary (null = auto-detect)
        'node_binary' => null,

        // Path to npm binary (null = auto-detect)
        'npm_binary' => null,

        // Path to Chrome/Chromium binary (null = use Puppeteer's bundled Chrome)
        'chrome_path' => null,

        // Timeout for browser rendering in seconds
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum execution time in seconds for queued scan jobs (--queue flag).
    | Long-running scans with many URLs may need a higher value. The job
    | will be marked as failed if it exceeds this timeout.
    |
    */

    'job_timeout' => 600,

    /*
    |--------------------------------------------------------------------------
    | Downloadable File Extensions
    |--------------------------------------------------------------------------
    |
    | File extensions considered "downloadable" when scanning inline <script>
    | content for download URLs (requires --js flag). Only string literals
    | ending in one of these extensions and starting with "/" or "http" will
    | be extracted. This prevents false positives from random JS strings.
    |
    */

    'download_extensions' => [
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
        'rtf', 'txt', 'odt', 'ods', 'odp', 'epub',
        // Archives
        'zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz',
        // Media
        'mp3', 'mp4', 'wav', 'avi', 'mov', 'wmv', 'flv', 'webm', 'ogg', 'mkv',
        // Installers
        'dmg', 'exe', 'msi', 'deb', 'rpm', 'apk', 'ipa',
        // Images (download context)
        'svg', 'psd', 'ai', 'eps',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Handling (HTTP 429)
    |--------------------------------------------------------------------------
    |
    | When the scanner receives HTTP 429 (Too Many Requests) responses, it will
    | automatically retry with exponential backoff. The delays are applied in
    | sequence for each retry attempt.
    |
    | If the server sends a Retry-After header (in seconds), the scanner will
    | use that value instead of the configured delay (when respect_retry_after
    | is enabled).
    |
    | After max_429_before_abort total 429 responses during a scan, the scan
    | will be aborted to prevent excessive waiting and server strain.
    |
    */

    'rate_limit' => [
        // Backoff delays in milliseconds for each retry attempt
        // First 429: wait 2s, second: wait 5s, third: wait 10s
        'backoff_delays' => [2000, 5000, 10000],

        // Whether to respect the Retry-After header from the server (in seconds)
        'respect_retry_after' => true,

        // Maximum total 429 responses before aborting the scan
        // Set to 0 to disable abort (will always retry with backoff)
        'max_429_before_abort' => 5,
    ],

];

