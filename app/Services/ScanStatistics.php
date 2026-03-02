<?php

namespace App\Services;

/**
 * Service for calculating and filtering scan result statistics.
 *
 * Provides methods to compute counts for OK responses, redirects,
 * broken links, timeouts, and other metrics, as well as filtering
 * results by status or element type.
 */
class ScanStatistics
{
    /**
     * Calculate statistics from scan results.
     *
     * Computes counts for OK responses, redirects, broken links,
     * timeouts, and other metrics.
     *
     * @param  array  $results  Array of scan result items.
     * @return array{
     *     total: int,
     *     ok: int,
     *     redirects: int,
     *     broken: int,
     *     timeouts: int,
     *     redirectChainCount: int,
     *     totalRedirectHops: int,
     *     httpsDowngrades: int,
     *     needsVerificationCount: int
     * }
     */
    public function calculateStats(array $results): array
    {
        $total = count($results);
        $ok = count(array_filter($results, fn($r) => $r['isOk'] && empty($r['redirectChain'])));
        $redirects = count(array_filter($results, fn($r) => !empty($r['redirectChain']) && $r['isOk']));
        $broken = count(array_filter($results, fn($r) => !$r['isOk'] && $r['status'] !== 'Timeout'));
        $timeouts = count(array_filter($results, fn($r) => $r['status'] === 'Timeout'));

        // Redirect chain statistics — only for internal URLs (external chains are not actionable)
        $redirectChainCount = count(array_filter($results, fn($r) => ($r['type'] ?? 'internal') === 'internal' && count($r['redirectChain'] ?? []) >= 2));
        $totalRedirectHops = array_sum(array_map(fn($r) => ($r['type'] ?? 'internal') === 'internal' ? count($r['redirectChain'] ?? []) : 0, $results));

        // HTTPS downgrade count
        $httpsDowngrades = count(array_filter($results, fn($r) => $r['hasHttpsDowngrade'] ?? false));

        // Verification count — URLs that need manual verification
        $needsVerificationCount = count(array_filter($results, fn($r) => $r['needsVerification'] ?? false));

        return [
            'total' => $total,
            'ok' => $ok,
            'redirects' => $redirects,
            'broken' => $broken,
            'timeouts' => $timeouts,
            'redirectChainCount' => $redirectChainCount,
            'totalRedirectHops' => $totalRedirectHops,
            'httpsDowngrades' => $httpsDowngrades,
            'needsVerificationCount' => $needsVerificationCount,
        ];
    }

    /**
     * Filter scan results by status.
     *
     * @param  array   $results  Array of scan result items.
     * @param  string  $filter   Filter type: 'all', 'ok', or 'broken'.
     * @return array Filtered results.
     */
    public function filterResults(array $results, string $filter): array
    {
        return match ($filter) {
            'ok' => array_filter($results, fn($r) => $r['isOk']),
            'broken' => array_filter($results, fn($r) => !$r['isOk']),
            default => $results,
        };
    }

    /**
     * Filter scan results by source element type.
     *
     * @param  array   $results  Array of scan result items.
     * @param  string  $element  Element filter: 'all', 'a', 'link', 'script', 'img', or 'media'.
     * @return array Filtered results.
     */
    public function filterByElement(array $results, string $element): array
    {
        if ($element === 'all') {
            return $results;
        }

        return array_filter($results, fn($r) => ($r['sourceElement'] ?? 'a') === $element);
    }

    /**
     * Filter out noise URLs (XML namespaces, preconnect hints, JS framework docs).
     *
     * Uses dynamic detection strategies from the noise config:
     * - 'namespace_domains': Any URL on known namespace-hosting domains.
     * - 'detect_preconnect': Bare external domain URLs from <link> elements.
     * - 'framework_error_patterns': Regex patterns for JS framework error docs.
     * - 'exact': Additional exact URL matches.
     * - 'prefix': Additional URL prefix matches.
     *
     * @param  array  $results        Array of scan result items.
     * @param  array  $noisePatterns  Noise config array.
     * @return array Filtered results with noise URLs removed.
     */
    public function filterNoiseUrls(array $results, array $noisePatterns): array
    {
        $namespaceDomains = $noisePatterns['namespace_domains'] ?? [];
        $detectPreconnect = $noisePatterns['detect_preconnect'] ?? false;
        $frameworkPatterns = $noisePatterns['framework_error_patterns'] ?? [];
        $exactUrls = $noisePatterns['exact'] ?? [];
        $prefixUrls = $noisePatterns['prefix'] ?? [];

        if (empty($namespaceDomains) && !$detectPreconnect && empty($frameworkPatterns) && empty($exactUrls) && empty($prefixUrls)) {
            return $results;
        }

        return array_filter($results, function ($result) use ($namespaceDomains, $detectPreconnect, $frameworkPatterns, $exactUrls, $prefixUrls) {
            $url = $result['url'] ?? '';
            $element = $result['sourceElement'] ?? 'a';
            $type = $result['type'] ?? 'internal';

            // 1. Namespace domain detection — any URL on a known namespace domain
            if (!empty($namespaceDomains)) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host !== null && $host !== false && in_array($host, $namespaceDomains, true)) {
                    return false;
                }
            }

            // 2. Preconnect/dns-prefetch detection — bare external domain in <link>
            if ($detectPreconnect && $element === 'link' && $type === 'external') {
                $parsed = parse_url($url);
                $path = $parsed['path'] ?? '';
                $hasQuery = isset($parsed['query']);
                // Bare domain: no path (or just "/") and no query string
                if (($path === '' || $path === '/') && !$hasQuery) {
                    return false;
                }
            }

            // 3. Framework error pattern detection
            foreach ($frameworkPatterns as $pattern) {
                if (preg_match($pattern, $url)) {
                    return false;
                }
            }

            // 4. Exact match fallback
            if (in_array($url, $exactUrls, true)) {
                return false;
            }

            // 5. Prefix match fallback
            foreach ($prefixUrls as $prefix) {
                if (str_starts_with($url, $prefix)) {
                    return false;
                }
            }

            return true;
        });
    }
}

