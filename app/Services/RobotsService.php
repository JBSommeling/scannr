<?php

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Service for parsing and enforcing robots.txt rules.
 *
 * Fetches and parses robots.txt from a target website, extracting
 * Disallow/Allow rules and Crawl-delay for the User-agent: * block.
 * Supports wildcard (*) and end-of-string ($) patterns in paths.
 */
class RobotsService
{
    /**
     * The HTTP client instance.
     */
    protected Client $client;

    /**
     * Parsed rules for User-agent: *.
     * Each rule has a type (allow/disallow) and a pattern.
     */
    protected array $rules = [];

    /**
     * The Crawl-delay value in seconds (null if not specified).
     */
    protected ?float $crawlDelay = null;

    /**
     * Sitemap URLs found in robots.txt.
     */
    protected array $sitemapUrls = [];

    /**
     * Whether robots.txt has been fetched and parsed.
     */
    protected bool $parsed = false;

    /**
     * Create a new RobotsService instance.
     */
    public function __construct(?Client $client = null)
    {
        $defaultUserAgent = 'ScannrBot/1.0 (+https://scannr.io)';
        try {
            $userAgent = config('scanner.user_agent', $defaultUserAgent) ?? $defaultUserAgent;
        } catch (\Throwable) {
            $userAgent = $defaultUserAgent;
        }

        $this->client = $client ?? new Client([
            'timeout' => 10,
            'allow_redirects' => true,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => $userAgent,
            ],
        ]);
    }

    /**
     * Set the HTTP client instance (primarily for testing).
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Fetch and parse robots.txt from the given base URL.
     */
    public function fetchAndParse(string $baseUrl): self
    {
        $this->rules = [];
        $this->crawlDelay = null;
        $this->sitemapUrls = [];
        $this->parsed = true;

        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = preg_replace('#://www\.#i', '://', $baseUrl);
        $robotsUrl = $baseUrl . '/robots.txt';

        try {
            $response = $this->client->request('GET', $robotsUrl);

            if ($response->getStatusCode() !== 200) {
                return $this;
            }

            $content = (string) $response->getBody();
            $this->parseContent($content);
        } catch (\Exception $e) {
            // Failed to fetch robots.txt = allow everything
        }

        return $this;
    }

    /**
     * Parse the robots.txt content.
     *
     * Extracts rules for User-agent: * (or ScannrBot if specified),
     * Crawl-delay, and Sitemap directives.
     */
    public function parseContent(string $content): self
    {
        $this->rules = [];
        $this->crawlDelay = null;
        $this->sitemapUrls = [];
        $this->parsed = true;

        $lines = preg_split("/\r\n|\r|\n/", $content);
        $currentAgents = [];
        $inRelevantBlock = false;
        $hasSeenDirective = false;
        $scannrBotRules = [];
        $scannrBotDelay = null;
        $wildcardRules = [];
        $wildcardDelay = null;
        $hasScannrBotBlock = false;

        foreach ($lines as $line) {
            // Remove comments
            $commentPos = strpos($line, '#');
            if ($commentPos !== false) {
                $line = substr($line, 0, $commentPos);
            }
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Check for Sitemap directive (always global)
            if (preg_match('/^Sitemap:\s*(.+)/i', $line, $matches)) {
                $this->sitemapUrls[] = trim($matches[1]);
                continue;
            }

            // Check for User-agent directive
            if (preg_match('/^User-agent:\s*(.+)/i', $line, $matches)) {
                $agent = trim($matches[1]);

                // If we've seen directives, this is a NEW block - reset
                if ($hasSeenDirective) {
                    $currentAgents = [$agent];
                    $hasSeenDirective = false;
                } elseif (empty($currentAgents)) {
                    $currentAgents = [$agent];
                } else {
                    // Multiple User-agent lines in a row = same block
                    $currentAgents[] = $agent;
                }

                // Determine if this block is relevant
                $inRelevantBlock = false;
                foreach ($currentAgents as $a) {
                    if ($a === '*' || strcasecmp($a, 'ScannrBot') === 0) {
                        $inRelevantBlock = true;
                        break;
                    }
                }

                continue;
            }

            // Parse directives within a relevant block
            if ($inRelevantBlock) {
                $hasSeenDirective = true;

                $isScannrBot = false;
                $isWildcard = false;
                foreach ($currentAgents as $a) {
                    if (strcasecmp($a, 'ScannrBot') === 0) {
                        $isScannrBot = true;
                        $hasScannrBotBlock = true;
                    }
                    if ($a === '*') {
                        $isWildcard = true;
                    }
                }

                if (preg_match('/^Disallow:\s*(.*)/i', $line, $matches)) {
                    $path = trim($matches[1]);
                    if ($path !== '') {
                        $rule = ['type' => 'disallow', 'pattern' => $path];
                        if ($isScannrBot) {
                            $scannrBotRules[] = $rule;
                        }
                        if ($isWildcard) {
                            $wildcardRules[] = $rule;
                        }
                    }
                } elseif (preg_match('/^Allow:\s*(.*)/i', $line, $matches)) {
                    $path = trim($matches[1]);
                    if ($path !== '') {
                        $rule = ['type' => 'allow', 'pattern' => $path];
                        if ($isScannrBot) {
                            $scannrBotRules[] = $rule;
                        }
                        if ($isWildcard) {
                            $wildcardRules[] = $rule;
                        }
                    }
                } elseif (preg_match('/^Crawl-delay:\s*(\d+\.?\d*)/i', $line, $matches)) {
                    $delay = (float) $matches[1];
                    if ($isScannrBot) {
                        $scannrBotDelay = $delay;
                    }
                    if ($isWildcard) {
                        $wildcardDelay = $delay;
                    }
                }
            } else {
                // Track that we've seen a directive even in non-relevant blocks
                // so the next User-agent starts a fresh block
                if (preg_match('/^(Disallow|Allow|Crawl-delay):/i', $line)) {
                    $hasSeenDirective = true;
                }
            }
        }

        // Prefer ScannrBot-specific rules over wildcard
        if ($hasScannrBotBlock) {
            $this->rules = $scannrBotRules;
            $this->crawlDelay = $scannrBotDelay ?? $wildcardDelay;
        } else {
            $this->rules = $wildcardRules;
            $this->crawlDelay = $wildcardDelay;
        }

        return $this;
    }

    /**
     * Check if a URL is allowed to be crawled based on the parsed robots.txt rules.
     *
     * Uses longest-match-wins precedence when both Allow and Disallow match.
     */
    public function isAllowed(string $url): bool
    {
        if (!$this->parsed || empty($this->rules)) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query !== null) {
            $path .= '?' . $query;
        }

        $bestMatch = null;
        $bestLength = -1;

        foreach ($this->rules as $rule) {
            $pattern = $rule['pattern'];

            if ($this->pathMatches($path, $pattern)) {
                $patternLength = $this->getPatternSpecificity($pattern);
                if ($patternLength > $bestLength) {
                    $bestLength = $patternLength;
                    $bestMatch = $rule;
                }
            }
        }

        if ($bestMatch === null) {
            return true;
        }

        return $bestMatch['type'] === 'allow';
    }

    /**
     * Check if a URL path matches a robots.txt pattern.
     *
     * Supports prefix matching, wildcard (*), and end anchor ($).
     */
    public function pathMatches(string $path, string $pattern): bool
    {
        $hasEndAnchor = str_ends_with($pattern, '$');
        if ($hasEndAnchor) {
            $pattern = substr($pattern, 0, -1);
        }

        if (str_contains($pattern, '*')) {
            $regex = preg_quote($pattern, '#');
            $regex = str_replace('\*', '.*', $regex);

            if ($hasEndAnchor) {
                $regex = '#^' . $regex . '$#';
            } else {
                $regex = '#^' . $regex . '#';
            }

            return (bool) preg_match($regex, $path);
        }

        if ($hasEndAnchor) {
            return $path === $pattern;
        }

        return str_starts_with($path, $pattern);
    }

    /**
     * Get the specificity of a pattern for longest-match-wins comparison.
     */
    protected function getPatternSpecificity(string $pattern): int
    {
        $clean = str_replace(['$', '*'], '', $pattern);
        return strlen($clean);
    }

    /**
     * Get the Crawl-delay value in seconds.
     */
    public function getCrawlDelay(): ?float
    {
        return $this->crawlDelay;
    }

    /**
     * Get the sitemap URLs found in robots.txt.
     */
    public function getSitemapUrls(): array
    {
        return $this->sitemapUrls;
    }

    /**
     * Get the parsed rules.
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Check if robots.txt has been parsed.
     */
    public function isParsed(): bool
    {
        return $this->parsed;
    }

    /**
     * Reset the service state.
     */
    public function reset(): self
    {
        $this->rules = [];
        $this->crawlDelay = null;
        $this->sitemapUrls = [];
        $this->parsed = false;
        return $this;
    }
}

