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
     *     httpsDowngrades: int
     * }
     */
    public function calculateStats(array $results): array
    {
        $total = count($results);
        $ok = count(array_filter($results, fn($r) => $r['isOk'] && empty($r['redirectChain'])));
        $redirects = count(array_filter($results, fn($r) => !empty($r['redirectChain']) && $r['isOk']));
        $broken = count(array_filter($results, fn($r) => !$r['isOk'] && $r['status'] !== 'Timeout'));
        $timeouts = count(array_filter($results, fn($r) => $r['status'] === 'Timeout'));

        // Redirect chain statistics (a "chain" is 2+ hops; single redirects are just redirects)
        $redirectChainCount = count(array_filter($results, fn($r) => count($r['redirectChain'] ?? []) >= 2));
        $totalRedirectHops = array_sum(array_map(fn($r) => count($r['redirectChain'] ?? []), $results));

        // HTTPS downgrade count
        $httpsDowngrades = count(array_filter($results, fn($r) => $r['hasHttpsDowngrade'] ?? false));

        return [
            'total' => $total,
            'ok' => $ok,
            'redirects' => $redirects,
            'broken' => $broken,
            'timeouts' => $timeouts,
            'redirectChainCount' => $redirectChainCount,
            'totalRedirectHops' => $totalRedirectHops,
            'httpsDowngrades' => $httpsDowngrades,
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
}

