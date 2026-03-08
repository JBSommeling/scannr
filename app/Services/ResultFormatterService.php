<?php

namespace App\Services;

use App\Contracts\OutputInterface;
use App\DTO\ScanConfig;

/**
 * Service for formatting and displaying scan results.
 *
 * Handles table, JSON, and CSV output formats.
 */
class ResultFormatterService
{
    public function __construct(
        protected ScanStatistics $scanStatistics,
    ) {}

    /**
     * Format and display results based on configuration.
     *
     * @param  array  $results  The scan results.
     * @param  ScanConfig  $config  The scan configuration.
     * @param  OutputInterface  $output  The output interface.
     * @param  string|null  $error  Optional error message (e.g., rate limit abort).
     */
    public function format(array $results, ScanConfig $config, OutputInterface $output, ?string $error = null): void
    {
        // Remove noise URLs unless --advanced is used
        if (! $config->showAdvanced) {
            $results = $this->scanStatistics->filterNoiseUrls($results, config('scanner.noise_urls', []));
        }

        // Filter results
        $filtered = $this->scanStatistics->filterResults($results, $config->statusFilter);
        $filtered = $this->scanStatistics->filterByElement($filtered, $config->elementFilter);

        // Calculate stats
        $stats = $this->scanStatistics->calculateStats($filtered);
        $totalScanned = count($results);
        $isFiltered = $config->hasFilter();

        // Display based on format
        match ($config->outputFormat) {
            'json' => $this->displayJson($filtered, $stats, $totalScanned, $isFiltered, $output, $error),
            'csv' => $this->displayCsv($filtered, $output, $error),
            default => $this->displayTable($filtered, $stats, $totalScanned, $isFiltered, $output, $error),
        };
    }

    /**
     * Display results as a table.
     */
    protected function displayTable(array $results, array $stats, int $totalScanned, bool $isFiltered, OutputInterface $output, ?string $error = null): void
    {
        // Display error message if present (e.g., rate limit abort)
        if ($error !== null) {
            $output->error("⚠ Error: {$error}");
            $output->newLine();
        }

        $output->info('Summary:');
        $output->line("  Total scanned:  {$totalScanned}");

        if ($isFiltered) {
            $output->line("  Filtered:       {$stats['total']}");
        }

        $output->line("  Working (2xx):  {$stats['ok']}");
        $output->line("  Redirects:      {$stats['redirects']}");
        $output->line("  Broken:         {$stats['broken']}");
        $output->line("  Timeouts:       {$stats['timeouts']}");
        $output->newLine();

        // Redirect chain summary
        if ($stats['redirectChainCount'] > 0) {
            $output->line("  ⚠ Redirect chains: {$stats['redirectChainCount']} chains, {$stats['totalRedirectHops']} total hops");
        }

        // HTTPS downgrade warning
        if ($stats['httpsDowngrades'] > 0) {
            $output->warn("  ⚠ HTTPS downgrades: {$stats['httpsDowngrades']}");

            if ($output->isVerbose()) {
                $downgradedUrls = array_filter($results, fn ($r) => $r['redirect']['hasHttpsDowngrade'] ?? $r['hasHttpsDowngrade'] ?? false);
                foreach ($downgradedUrls as $result) {
                    $output->line("    - {$result['url']}");
                }
            }
        }

        // Critical issues alert
        if ($stats['criticalCount'] > 0) {
            $output->error("  ⚠ Critical issues: {$stats['criticalCount']}");
        }

        // Warning issues alert
        if ($stats['warningCount'] > 0) {
            $output->warn("  ⚠ Warnings: {$stats['warningCount']}");
        }

        // Low confidence issues
        if ($stats['lowConfidenceCount'] > 0) {
            $output->warn("  ⚠ Low confidence (verify manually): {$stats['lowConfidenceCount']}");
        }

        // Broken links alert
        if ($stats['broken'] > 0) {
            $output->error("  ⚠ Broken links: {$stats['broken']}");
        }

        if ($stats['redirectChainCount'] > 0 || $stats['httpsDowngrades'] > 0 || $stats['criticalCount'] > 0 || $stats['warningCount'] > 0 || $stats['broken'] > 0) {
            $output->newLine();
        }

        if (empty($results)) {
            $output->info('No links to display for the selected filter.');

            return;
        }

        // Build table data
        $tableData = [];
        foreach ($results as $result) {
            $redirectChain = $result['redirect']['chain'] ?? $result['redirectChain'] ?? [];

            $row = [
                'URL' => $this->truncate($result['url'], 50),
                'Source' => $this->truncate($result['sourcePage'], 30),
                'Element' => '<'.($result['sourceElement'] ?? 'a').'>',
                'Status' => $this->formatStatus($result),
                'Type' => $result['type'],
            ];

            if ($output->isVerbose() && ! empty($redirectChain)) {
                $row['Redirects'] = implode(' → ', array_map(fn ($u) => $this->truncate($u, 30), $redirectChain));
            }

            $tableData[] = $row;
        }

        $headers = ['URL', 'Source', 'Element', 'Status', 'Type'];
        if ($output->isVerbose()) {
            $headers[] = 'Redirects';
        }

        $output->table($headers, $tableData);

        // Display critical issues, broken links, and low confidence links in separate tables
        $this->displayCriticalIssuesTable($results, $output);
        $this->displayBrokenLinksTable($results, $output);
        $this->displayLowConfidenceTable($results, $output);
    }

    /**
     * Display critical issues in a separate table.
     *
     * @param  array  $results  The scan results.
     * @param  OutputInterface  $output  The output interface.
     */
    protected function displayCriticalIssuesTable(array $results, OutputInterface $output): void
    {
        $criticalIssues = array_filter($results, fn ($r) => ($r['analysis']['severity'] ?? '') === 'critical');

        if (empty($criticalIssues)) {
            return;
        }

        $output->newLine();
        $output->error('Critical Issues:');

        $criticalTableData = [];
        foreach ($criticalIssues as $result) {
            $flags = $result['analysis']['flags'] ?? [];
            $reason = $this->getCriticalReason($flags, $result);
            $criticalTableData[] = [
                'URL' => $this->truncate($result['url'], 60),
                'Source' => $this->truncate($result['sourcePage'], 30),
                'Element' => '<'.($result['sourceElement'] ?? 'a').'>',
                'Status' => $result['status'],
                'Reason' => $reason,
            ];
        }

        $output->table(['URL', 'Source', 'Element', 'Status', 'Reason'], $criticalTableData);
    }

    /**
     * Display broken links in a separate table.
     *
     * @param  array  $results  The scan results.
     * @param  OutputInterface  $output  The output interface.
     */
    protected function displayBrokenLinksTable(array $results, OutputInterface $output): void
    {
        $brokenLinks = array_filter($results, fn ($r) => ! $this->isOkStatus($r['status'] ?? '') && ! $this->isHealthyFormEndpoint($r));

        if (empty($brokenLinks)) {
            return;
        }

        $output->newLine();
        $output->error('Broken Links:');

        $brokenTableData = [];
        foreach ($brokenLinks as $result) {
            $brokenTableData[] = [
                'URL' => $this->truncate($result['url'], 60),
                'Source' => $this->truncate($result['sourcePage'], 40),
                'Element' => '<'.($result['sourceElement'] ?? 'a').'>',
                'Status' => $result['status'],
                'Error' => $result['type'],
            ];
        }

        $output->table(['URL', 'Source', 'Element', 'Status', 'Error'], $brokenTableData);
    }

    /**
     * Display low confidence links in a separate table.
     *
     * @param  array  $results  The scan results.
     * @param  OutputInterface  $output  The output interface.
     */
    protected function displayLowConfidenceTable(array $results, OutputInterface $output): void
    {
        $lowConfidenceLinks = array_filter($results, fn ($r) => ($r['analysis']['confidence'] ?? '') === 'low');

        if (empty($lowConfidenceLinks)) {
            return;
        }

        $output->newLine();
        $output->warn('Low Confidence (Manual Verification Recommended):');

        $verificationTableData = [];
        foreach ($lowConfidenceLinks as $result) {
            $flags = $result['analysis']['flags'] ?? [];
            $verificationTableData[] = [
                'URL' => $this->truncate($result['url'], 60),
                'Source' => $this->truncate($result['sourcePage'], 40),
                'Element' => '<'.($result['sourceElement'] ?? 'a').'>',
                'Status' => $this->formatStatus($result),
                'Flags' => implode('|', $flags),
            ];
        }

        $output->table(['URL', 'Source', 'Element', 'Status', 'Flags'], $verificationTableData);
    }

    /**
     * Build the JSON output array from results and config.
     *
     * Returns the structured array with summary, results, and broken links.
     * Useful for storing scan output in the database without an OutputInterface.
     *
     * @param  array  $results  The scan results.
     * @param  ScanConfig  $config  The scan configuration.
     * @param  string|null  $error  Optional error message (e.g., rate limit abort).
     * @return array{summary: array, results: array, brokenLinks: array, error?: string}
     */
    public function toJsonArray(array $results, ScanConfig $config, ?string $error = null): array
    {
        // Remove noise URLs unless --advanced is used
        if (! $config->showAdvanced) {
            $results = $this->scanStatistics->filterNoiseUrls($results, config('scanner.noise_urls', []));
        }

        $filtered = $this->scanStatistics->filterResults($results, $config->statusFilter);
        $filtered = $this->scanStatistics->filterByElement($filtered, $config->elementFilter);

        $stats = $this->scanStatistics->calculateStats($filtered);
        $totalScanned = count($results);
        $isFiltered = $config->hasFilter();

        $brokenLinks = array_values(array_filter($filtered, fn ($r) => ! $this->isOkStatus($r['status'] ?? '') && ! $this->isHealthyFormEndpoint($r)));

        $summary = ['totalScanned' => $totalScanned];

        if ($isFiltered) {
            $summary['filtered'] = $stats['total'];
        }

        $statsWithoutTotal = array_diff_key($stats, ['total' => true]);
        $summary = array_merge($summary, $statsWithoutTotal);

        $output = [
            'summary' => $summary,
            'results' => array_values($filtered),
            'brokenLinks' => $brokenLinks,
        ];

        if ($error !== null) {
            $output['error'] = $error;
        }

        return $output;
    }

    /**
     * Display results as JSON.
     */
    protected function displayJson(array $results, array $stats, int $totalScanned, bool $isFiltered, OutputInterface $output, ?string $error = null): void
    {
        $brokenLinks = array_values(array_filter($results, fn ($r) => ! $this->isOkStatus($r['status'] ?? '') && ! $this->isHealthyFormEndpoint($r)));

        // Build summary
        $summary = ['totalScanned' => $totalScanned];

        if ($isFiltered) {
            $summary['filtered'] = $stats['total'];
        }

        $statsWithoutTotal = array_diff_key($stats, ['total' => true]);
        $summary = array_merge($summary, $statsWithoutTotal);

        $jsonOutput = [
            'summary' => $summary,
            'results' => array_values($results),
            'brokenLinks' => $brokenLinks,
        ];

        // Add error to output if present
        if ($error !== null) {
            $jsonOutput['error'] = $error;
        }

        $output->line(json_encode($jsonOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Display results as CSV.
     */
    protected function displayCsv(array $results, OutputInterface $output, ?string $error = null): void
    {
        // Display error as comment line at the top if present
        if ($error !== null) {
            $output->line("# Error: {$error}");
        }

        $output->line('URL,Source,Element,Status,Type,Redirects,Flags,Confidence,Verification');

        foreach ($results as $result) {
            $redirectChain = $result['redirect']['chain'] ?? $result['redirectChain'] ?? [];
            $redirects = implode(' -> ', $redirectChain);
            $element = $result['sourceElement'] ?? 'a';
            $flags = implode('|', $result['analysis']['flags'] ?? []);
            $confidence = $result['analysis']['confidence'] ?? '';
            $verification = $result['analysis']['verification'] ?? '';

            $output->line(sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $result['url']),
                str_replace('"', '""', $result['sourcePage']),
                $element,
                $result['status'],
                $result['type'],
                str_replace('"', '""', $redirects),
                $flags,
                $confidence,
                $verification
            ));
        }
    }

    /**
     * Get a user-friendly reason for why a link is flagged as critical.
     *
     * @param  array<string>  $flags  The flag values
     * @param  array  $result  The result data
     * @return string User-friendly explanation
     */
    protected function getCriticalReason(array $flags, array $result): string
    {
        $status = $result['status'] ?? '';
        $type = $result['type'] ?? 'internal';

        // Check for specific flags in priority order (matching SeverityEvaluator logic)

        // Internal 4xx (most common critical issue)
        if (in_array('status_4xx', $flags, true) && $type === 'internal' && ! in_array('bot_protection', $flags, true)) {
            return 'Broken internal link (4xx)';
        }

        // 5xx server errors
        if (in_array('status_5xx', $flags, true)) {
            return 'Server error (5xx)';
        }

        // Connection errors
        if (in_array('connection_error', $flags, true)) {
            return 'Connection failed';
        }

        // Fallback: show first flag or generic message
        if (! empty($flags)) {
            return ucfirst(str_replace('_', ' ', $flags[0]));
        }

        return "Status: {$status}";
    }

    /**
     * Truncate a string to a maximum length.
     */
    protected function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3).'...';
    }

    /**
     * Check if a status indicates success (2xx).
     */
    protected function isOkStatus(string|int $status): bool
    {
        if (is_numeric($status)) {
            $statusInt = (int) $status;

            return $statusInt >= 200 && $statusInt < 300;
        }

        return false;
    }

    /**
     * Check if a result is a healthy form endpoint (has form_endpoint flag).
     */
    protected function isHealthyFormEndpoint(array $result): bool
    {
        $flags = $result['analysis']['flags'] ?? [];

        return in_array('form_endpoint', $flags, true);
    }

    /**
     * Format a status code for display.
     *
     * For form endpoints that return non-2xx but are considered healthy
     * (e.g., 422 Unprocessable Entity from posting empty data), appends
     * "(ok)" to make it clear the endpoint exists and is functional.
     *
     * For URLs with low confidence, appends "(verify)" in table output.
     *
     * @param  array  $result  The scan result item.
     * @param  bool  $isTableOutput  Whether formatting for table (vs JSON/CSV).
     * @return string The formatted status for display.
     */
    protected function formatStatus(array $result, bool $isTableOutput = true): string
    {
        $status = $result['status'] ?? '';
        $element = $result['sourceElement'] ?? 'a';
        $confidence = $result['analysis']['confidence'] ?? 'high';
        $flags = $result['analysis']['flags'] ?? [];

        // Form endpoints with healthy non-2xx statuses
        $healthyFormStatuses = ['400', '401', '403', '405', '422', '429'];
        if ($element === 'form' && in_array((string) $status, $healthyFormStatuses, true)) {
            return "{$status} (ok)";
        }

        // Annotate URLs needing verification (low confidence)
        if ($confidence === 'low' && $isTableOutput) {
            return "{$status} (verify)";
        }

        // Annotate bot protection
        if (in_array('bot_protection', $flags, true) && $isTableOutput) {
            return "{$status} (bot?)";
        }

        return (string) $status;
    }
}
