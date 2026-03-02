<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for extracting links from HTML content.
 *
 * Parses HTML and extracts URLs from anchor tags, forms, images, scripts,
 * media elements, data attributes, onclick handlers, and inline/external
 * JavaScript content.
 */
class LinkExtractor
{
    /**
     * Create a new LinkExtractor instance.
     *
     * @param  UrlNormalizer  $urlNormalizer  The URL normalizer for resolving URLs.
     * @param  HttpChecker    $httpChecker    The HTTP checker for fetching external script content.
     */
    public function __construct(
        protected UrlNormalizer $urlNormalizer,
        protected HttpChecker $httpChecker,
    ) {}

    /**
     * Extract all links from HTML content.
     *
     * Parses HTML and extracts URLs from:
     * - <a href=""> (anchor links)
     * - <form action=""> (form submission endpoints)
     * - <link href=""> (stylesheets, icons, etc.)
     * - <script src=""> (JavaScript files)
     * - <img src=""> (images)
     * - <video>, <audio>, <source>, <object>, <embed> (media/downloads)
     * - <a download> (explicit download links)
     * - Elements with data-href, data-url, data-download, data-src, data-file (JS-triggered downloads)
     * - onclick attributes containing window.location, window.open, or download() calls
     * - Inline <script> contents referencing downloadable file URLs (when --js is enabled)
     * - External <script src=""> JS bundles for downloadable file URLs (when --js is enabled, internal only)
     * - URLs embedded in inline/external JS bundles by SPA frameworks (React, Vue, Svelte, etc.) (when --js is enabled)
     * - Form submission endpoints in JS: fetch(), axios, $.ajax, XMLHttpRequest (when --js is enabled)
     *
     * Filters out javascript:, mailto:, tel:, and fragment-only links.
     * Normalizes relative URLs to absolute URLs.
     *
     * @param  string  $html               The HTML content to parse.
     * @param  string  $sourceUrl          The URL the HTML was fetched from (for resolving relative URLs).
     * @param  bool    $scanScriptContent  Whether to scan <script> contents for URLs, downloads, and form endpoints (requires --js).
     * @return array<array{url: string, source: string, element: string}> Array of extracted links with URL, source page, and element type.
     */
    public function extractLinks(string $html, string $sourceUrl, bool $scanScriptContent = false): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html, $sourceUrl);

            // Extract from <a href="">
            $crawler->filter('a[href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'a', $links);
            });

            // Extract from <form action=""> (form submission endpoints)
            $crawler->filter('form[action]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'action', $sourceUrl, 'form', $links);
            });

            // Extract from <link href=""> (stylesheets, icons, etc.)
            $crawler->filter('link[href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'link', $links);
            });

            // Extract from <script src="">
            $crawler->filter('script[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'script', $links);
            });

            // Extract from <img src="">
            $crawler->filter('img[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'img', $links);
            });

            // Extract from <img srcset=""> (responsive images)
            $crawler->filter('img[srcset]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinksFromSrcset($node, $sourceUrl, 'img', $links);
            });

            // Extract from <img data-src=""> (lazy loading)
            $crawler->filter('img[data-src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'data-src', $sourceUrl, 'img', $links);
            });

            // Extract from <source srcset=""> inside <picture> elements
            $crawler->filter('picture source[srcset]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinksFromSrcset($node, $sourceUrl, 'img', $links);
            });

            // Extract from <source src=""> inside <picture> elements
            $crawler->filter('picture source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'img', $links);
            });

            // Extract from <video src="">
            $crawler->filter('video[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <video poster="">
            $crawler->filter('video[poster]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'poster', $sourceUrl, 'media', $links);
            });

            // Extract from <audio src="">
            $crawler->filter('audio[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <source src=""> inside <video> and <audio> elements
            $crawler->filter('video source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });
            $crawler->filter('audio source[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <object data="">
            $crawler->filter('object[data]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'data', $sourceUrl, 'media', $links);
            });

            // Extract from <embed src="">
            $crawler->filter('embed[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'src', $sourceUrl, 'media', $links);
            });

            // Extract from <a download> (explicit download links, classified as media)
            $crawler->filter('a[download][href]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $this->addLinkFromAttribute($node, 'href', $sourceUrl, 'media', $links);
            });

            // Extract from elements with data attributes commonly used for JS-triggered downloads
            foreach (['data-href', 'data-url', 'data-download', 'data-src', 'data-file'] as $attr) {
                $crawler->filter("[{$attr}]")->each(function (Crawler $node) use ($attr, $sourceUrl, &$links) {
                    // Skip img[data-src] — already handled above as 'img' element
                    if ($attr === 'data-src' && strtolower($node->nodeName()) === 'img') {
                        return;
                    }
                    $this->addLinkFromAttribute($node, $attr, $sourceUrl, 'media', $links);
                });
            }

            // Extract download URLs from onclick attributes
            $crawler->filter('[onclick]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                $onclick = $node->attr('onclick');
                if ($onclick === null) {
                    return;
                }
                $this->addLinksFromInlineJs($onclick, $sourceUrl, $links);
            });

            // Scan inline and external JS bundles for embedded URLs, downloads,
            // and form endpoints (requires --js flag for headless browser rendering).
            if ($scanScriptContent) {
                $crawler->filter('script:not([src])')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                    $content = $node->text('', false);
                    if ($content === '') {
                        return;
                    }
                    $this->addUrlsFromJsBundleContent($content, $sourceUrl, $links);
                    $this->addDownloadUrlsFromScriptContent($content, $sourceUrl, $links);
                    $this->addFormEndpointUrlsFromScriptContent($content, $sourceUrl, $links);
                });

                // Fetch and scan external JS bundles (internal only)
                $crawler->filter('script[src]')->each(function (Crawler $node) use ($sourceUrl, &$links) {
                    $src = $node->attr('src');
                    if ($src === null || $src === '') {
                        return;
                    }

                    $scriptUrl = $this->urlNormalizer->normalizeUrl($src, $sourceUrl);
                    if ($scriptUrl === null || !$this->urlNormalizer->isInternalUrl($scriptUrl)) {
                        return;
                    }

                    $content = $this->httpChecker->fetchScriptContent($scriptUrl);
                    if ($content !== null) {
                        $this->addUrlsFromJsBundleContent($content, $sourceUrl, $links);
                        $this->addDownloadUrlsFromScriptContent($content, $sourceUrl, $links);
                        $this->addFormEndpointUrlsFromScriptContent($content, $sourceUrl, $links);
                    }
                });
            }
        } catch (\Exception $e) {
            // Silently handle parsing errors
        }

        return $links;
    }

    /**
     * Add a link from an element attribute to the links array.
     *
     * @param  Crawler  $node       The DOM node to extract from.
     * @param  string   $attribute  The attribute name ('href' or 'src').
     * @param  string   $sourceUrl  The source page URL.
     * @param  string   $element    The element type ('a', 'link', 'script', 'img', 'media', 'form').
     * @param  array    &$links     Reference to the links array.
     * @return void
     */
    protected function addLinkFromAttribute(Crawler $node, string $attribute, string $sourceUrl, string $element, array &$links): void
    {
        $value = $node->attr($attribute);

        if ($value === null || $value === '') {
            return;
        }

        // Skip javascript:, mailto:, tel:, data:, etc.
        if (preg_match('/^(javascript|mailto|tel|data|#)/', $value)) {
            return;
        }

        $normalizedUrl = $this->urlNormalizer->normalizeUrl($value, $sourceUrl);

        if ($normalizedUrl === null) {
            return;
        }

        $links[] = [
            'url' => $normalizedUrl,
            'source' => $sourceUrl,
            'element' => $element,
        ];
    }

    /**
     * Extract URLs from inline JavaScript code (e.g., onclick attributes).
     *
     * @param  string  $js         The inline JavaScript code.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addLinksFromInlineJs(string $js, string $sourceUrl, array &$links): void
    {
        $patterns = [
            '/(?:window\.)?location(?:\.href)?\s*=\s*[\'"]([^\'"]+)[\'"]/i',
            '/window\.open\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
            '/download\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $js, $matches)) {
                foreach ($matches[1] as $url) {
                    // Skip javascript:, mailto:, tel:, data: URLs
                    if (preg_match('/^(javascript|mailto|tel|data|#)/', $url)) {
                        continue;
                    }

                    $normalizedUrl = $this->urlNormalizer->normalizeUrl($url, $sourceUrl);
                    if ($normalizedUrl !== null) {
                        $links[] = [
                            'url' => $normalizedUrl,
                            'source' => $sourceUrl,
                            'element' => 'media',
                        ];
                    }
                }
            }
        }
    }

    /**
     * Extract downloadable file URLs from inline script content.
     *
     * @param  string  $content    The inline script content.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addDownloadUrlsFromScriptContent(string $content, string $sourceUrl, array &$links): void
    {
        $extensions = $this->getDownloadExtensions();

        if (empty($extensions)) {
            return;
        }

        // Unescape JSON forward-slash escaping
        $content = str_replace('\\/', '/', $content);

        $extPattern = implode('|', array_map('preg_quote', $extensions));

        $pattern = '/[\'\"]((?:\/|https?:\/\/)[^\s\'"]*\.(?:' . $extPattern . '))[\'\"]/i';

        if (preg_match_all($pattern, $content, $matches)) {
            $seen = [];
            foreach ($matches[1] as $url) {
                // Deduplicate within this script block
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                // Skip data: URIs and fragments
                if (preg_match('/^(data|#)/', $url)) {
                    continue;
                }

                $normalizedUrl = $this->urlNormalizer->normalizeUrl($url, $sourceUrl);
                if ($normalizedUrl !== null) {
                    $links[] = [
                        'url' => $normalizedUrl,
                        'source' => $sourceUrl,
                        'element' => 'media',
                    ];
                }
            }
        }
    }

    /**
     * Get the list of downloadable file extensions from config.
     *
     * @return array<string>
     */
    protected function getDownloadExtensions(): array
    {
        try {
            return config('scanner.download_extensions', []);
        } catch (\Throwable) {
            return [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
                'rtf', 'txt', 'odt', 'ods', 'odp', 'epub',
                'zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz',
                'mp3', 'mp4', 'wav', 'avi', 'mov', 'wmv', 'flv', 'webm', 'ogg', 'mkv',
                'dmg', 'exe', 'msi', 'deb', 'rpm', 'apk', 'ipa',
                'svg', 'psd', 'ai', 'eps',
            ];
        }
    }

    /**
     * Get the list of form-related keywords from config.
     *
     * @return array<string>
     */
    protected function getFormKeywords(): array
    {
        $defaults = [
            'contact', 'message', 'inquiry', 'inquiries', 'feedback',
            'submit', 'form', 'send', 'mail', 'email',
            'subscribe', 'newsletter', 'signup', 'sign-up', 'register',
            'lead', 'booking', 'book', 'reservation', 'appointment', 'quote',
            'request', 'support', 'ticket', 'complaint',
            'checkout', 'order', 'payment', 'donate', 'donation',
            'apply', 'application', 'enroll', 'enrollment',
            'survey', 'rsvp', 'review', 'comment', 'reply',
            'upload', 'report', 'claim',
            'login', 'signin', 'sign-in', 'verify',
        ];

        try {
            return config('scanner.form_keywords') ?? $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * Extract form submission endpoint URLs from JavaScript/JSON content.
     *
     * @param  string  $content    The script content to scan.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addFormEndpointUrlsFromScriptContent(string $content, string $sourceUrl, array &$links): void
    {
        // Unescape JSON forward-slash escaping
        $content = str_replace('\\/', '/', $content);

        $urls = [];

        $formKeywords = implode('|', array_map('preg_quote', $this->getFormKeywords()));

        // Strategy 1: Known form service URL patterns (always extract these)
        $formServicePatterns = [
            '/[\'\"](https?:\/\/(?:formspree\.io|formcarry\.com|getform\.io|api\.web3forms\.com|formsubmit\.co|submit-form\.com|usebasin\.com|formbold\.com|fabform\.io|formkeep\.com|kwes\.io)\/[^\s\'"]*)[\'\"]/i',
        ];

        foreach ($formServicePatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $url) {
                    $urls[] = $url;
                }
            }
        }

        // Strategy 2: API calls where the URL contains form-related keywords.
        $apiCallPatterns = [
            '/\bfetch\s*\(\s*[\'"]((?:\/|https?:\/\/)[^\s\'"]+)[\'"]/i',
            '/\baxios(?:\s*\.\s*(?:post|put|patch|delete))?\s*\(\s*[\'"]((?:\/|https?:\/\/)[^\s\'"]+)[\'"]/i',
            '/\$\s*\.\s*(?:ajax|post)\s*\(\s*[\'"]((?:\/|https?:\/\/)[^\s\'"]+)[\'"]/i',
            '/\$\s*\.\s*ajax\s*\(\s*\{[^}]*url\s*:\s*[\'"]((?:\/|https?:\/\/)[^\s\'"]+)[\'"]/i',
            '/\.open\s*\(\s*[\'"](?:POST|PUT|PATCH)[\'\"]\s*,\s*[\'"]((?:\/|https?:\/\/)[^\s\'"]+)[\'"]/i',
        ];

        foreach ($apiCallPatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $url) {
                    // Only include URLs that contain form-related keywords
                    if (preg_match('/(?:' . $formKeywords . ')/i', $url)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        // Strategy 3: API config objects with baseUrl + endpoint paths.
        if (preg_match_all('/baseUrl\s*:\s*[\'\"](https?:\/\/[^\s\'"]+)[\'"]/i', $content, $baseUrlMatches)) {
            foreach ($baseUrlMatches[1] as $baseUrl) {
                $baseUrl = rtrim($baseUrl, '/');

                $pos = strpos($content, $baseUrl);
                if ($pos !== false) {
                    $context = substr($content, $pos, 1000);

                    if (preg_match_all('/[\'\"](\/[^\s\'"]+)[\'"]/i', $context, $pathMatches)) {
                        foreach ($pathMatches[1] as $path) {
                            if (preg_match('/(?:' . $formKeywords . ')/i', $path)) {
                                $urls[] = $baseUrl . $path;
                            }
                        }
                    }
                }
            }
        }

        // Deduplicate and add to links
        $seen = [];
        foreach ($urls as $url) {
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            // Skip javascript:, mailto:, tel:, data: URLs
            if (preg_match('/^(javascript|mailto|tel|data|#)/', $url)) {
                continue;
            }

            $normalizedUrl = $this->urlNormalizer->normalizeUrl($url, $sourceUrl);
            if ($normalizedUrl !== null) {
                $links[] = [
                    'url' => $normalizedUrl,
                    'source' => $sourceUrl,
                    'element' => 'form',
                ];
            }
        }
    }

    /**
     * Add links from a srcset attribute to the links array.
     *
     * @param  Crawler  $node       The DOM node to extract from.
     * @param  string   $sourceUrl  The source page URL.
     * @param  string   $element    The element type ('img').
     * @param  array    &$links     Reference to the links array.
     * @return void
     */
    protected function addLinksFromSrcset(Crawler $node, string $sourceUrl, string $element, array &$links): void
    {
        $srcset = $node->attr('srcset');

        if ($srcset === null || $srcset === '') {
            return;
        }

        $candidates = preg_split('/,\s*(?=[^\s])/', $srcset);

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate, 2);
            $url = $parts[0] ?? '';

            if ($url === '') {
                continue;
            }

            // Skip data: URLs and fragment-only
            if (preg_match('/^(data:|#)/', $url)) {
                continue;
            }

            $normalizedUrl = $this->urlNormalizer->normalizeUrl($url, $sourceUrl);

            if ($normalizedUrl === null) {
                continue;
            }

            // Avoid duplicates in links array
            $alreadyAdded = false;
            foreach ($links as $link) {
                if ($link['url'] === $normalizedUrl && $link['element'] === $element) {
                    $alreadyAdded = true;
                    break;
                }
            }

            if (!$alreadyAdded) {
                $links[] = [
                    'url' => $normalizedUrl,
                    'source' => $sourceUrl,
                    'element' => $element,
                ];
            }
        }
    }

    /**
     * Extract URLs embedded in JavaScript bundle content.
     *
     * Discovers links compiled into JS bundles by any SPA framework
     * (React, Vue, Svelte, Angular, etc.) by searching for full URL
     * patterns (https://... or http://...) in the JavaScript source.
     *
     * @param  string  $content    The JavaScript content to scan.
     * @param  string  $sourceUrl  The source page URL.
     * @param  array   &$links     Reference to the links array.
     * @return void
     */
    protected function addUrlsFromJsBundleContent(string $content, string $sourceUrl, array &$links): void
    {
        // Extract full URLs from JavaScript (https://... or http://...)
        // Capture up to 3 chars after URL to detect suspicious patterns (e.g., ",n or `,r)
        if (preg_match_all('/(https?:\/\/[^\s"\')<>]+)(["\'`\s,]{0,3})/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rawUrl = $match[1];
                $postContext = $match[2] ?? '';

                // Check for suspicious syntax in URL itself OR in the post-context
                $hasSuspiciousSyntax = $this->hasSuspiciousDynamicUrlSyntax($rawUrl) ||
                                      preg_match('/["\'`]\s*,/', $postContext);

                // Clean up any trailing punctuation or quotes
                $url = rtrim($rawUrl, '.,;:"\')}>]');

                // Skip very common CDN/library URLs and analytics
                if (preg_match('/(googleapis|gstatic|cloudflare|jsdelivr|unpkg|cdnjs|analytics|gtag|facebook\.net)/i', $url)) {
                    continue;
                }

                $normalizedUrl = $this->urlNormalizer->normalizeUrl($url, $sourceUrl);

                if ($normalizedUrl === null) {
                    continue;
                }

                // Avoid duplicates
                $alreadyAdded = false;
                foreach ($links as $link) {
                    if ($link['url'] === $normalizedUrl) {
                        $alreadyAdded = true;
                        break;
                    }
                }

                if (!$alreadyAdded) {
                    // Check if this is an internal or external URL
                    // Note: UrlNormalizer's isInternalUrl uses the base URL from sourceUrl for comparison
                    $isInternal = $this->urlNormalizer->isInternalUrl($normalizedUrl);

                    // Only flag URLs that need verification:
                    // - Internal URLs: only if they have suspicious syntax
                    // - External URLs: always (could be library docs)
                    $needsVerification = $hasSuspiciousSyntax || !$isInternal;
                    $verificationReason = null;

                    if ($needsVerification) {
                        $verificationReason = $hasSuspiciousSyntax
                            ? 'suspicious_dynamic_url'
                            : 'js_bundle_extracted';
                    }

                    $links[] = [
                        'url' => $normalizedUrl,
                        'source' => $sourceUrl,
                        'element' => 'a', // Treat as anchor link
                        'needsVerification' => $needsVerification,
                        'verificationReason' => $verificationReason,
                    ];
                }
            }
        }
    }

    /**
     * Check if a URL contains suspicious dynamic syntax indicating an incomplete template literal.
     *
     * Detects patterns like:
     * - ${variable} - JavaScript template literal syntax
     * - #{variable} - Ruby/CoffeeScript interpolation
     * - {variable} or {{variable}} - Vue, Angular, Handlebars interpolation
     * - Backticks (`) - Incomplete template literal delimiters
     * - Unencoded commas or newlines - Malformed URLs
     * - Quotes followed by comma (array/concatenation fragments)
     *
     * @param  string  $url  The URL to check.
     * @return bool True if the URL contains suspicious dynamic syntax.
     */
    protected function hasSuspiciousDynamicUrlSyntax(string $url): bool
    {
        // Check for template literal syntax, curly braces, backticks, newlines, trailing commas, or quote+comma patterns
        if (preg_match('/\$\{|\#\{|\{|\}|`|,\w+$|"\s*,|\n/', $url)) {
            return true;
        }

        return false;
    }
}
