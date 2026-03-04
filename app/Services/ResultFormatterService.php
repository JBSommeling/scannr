<?php

namespace App\Services;

use App\Contracts\OutputInterface;
use App\DTO\ScanConfig;
use App\Enums\VerificationReason;

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
     * @param array $results The scan results.
     * @param ScanConfig $config The scan configuration.
     * @param OutputInterface $output The output interface.
     * @param string|null $error Optional error message (e.g., rate limit abort).
     */
    public function format(array $results, ScanConfig $config, OutputInterface $output, ?string $error = null): void
    {
        // Remove noise URLs unless --advanced is used
        if (!$config->showAdvanced) {
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
                $downgradedUrls = array_filter($results, fn($r) => $r['hasHttpsDowngrade'] ?? false);
                foreach ($downgradedUrls as $result) {
                    $output->line("    - {$result['url']}");
                }
            }
        }

        // Verification warning
        if ($stats['needsVerificationCount'] > 0) {
            $output->warn("  ⚠ Needs verification: {$stats['needsVerificationCount']}");
        }

        // Broken links alert
        if ($stats['broken'] > 0) {
            $output->error("  ⚠ Broken links: {$stats['broken']}");
        }

        if ($stats['redirectChainCount'] > 0 || $stats['httpsDowngrades'] > 0 || $stats['needsVerificationCount'] > 0 || $stats['broken'] > 0) {
            $output->newLine();
        }

        if (empty($results)) {
            $output->info('No links to display for the selected filter.');
            return;
        }

        // Build table data
        $tableData = [];
        foreach ($results as $result) {
            $row = [
                'URL' => $this->truncate($result['url'], 50),
                'Source' => $this->truncate($result['sourcePage'], 30),
                'Element' => '<' . ($result['sourceElement'] ?? 'a') . '>',
                'Status' => $this->formatStatus($result),
                'Type' => $result['type'],
            ];

            if ($output->isVerbose() && !empty($result['redirectChain'])) {
                $row['Redirects'] = implode(' → ', array_map(fn($u) => $this->truncate($u, 30), $result['redirectChain']));
            }

            $tableData[] = $row;
        }

        $headers = ['URL', 'Source', 'Element', 'Status', 'Type'];
        if ($output->isVerbose()) {
            $headers[] = 'Redirects';
        }

        $output->table($headers, $tableData);

        // Display broken links separately
        $brokenLinks = array_filter($results, fn($r) => !$r['isOk']);
        if (!empty($brokenLinks)) {
            $output->newLine();
            $output->error('Broken Links:');

            $brokenTableData = [];
            foreach ($brokenLinks as $result) {
                $brokenTableData[] = [
                    'URL' => $this->truncate($result['url'], 60),
                    'Source' => $this->truncate($result['sourcePage'], 40),
                    'Element' => '<' . ($result['sourceElement'] ?? 'a') . '>',
                    'Status' => $result['status'],
                    'Error' => $result['type'],
                ];
            }

            $output->table(['URL', 'Source', 'Element', 'Status', 'Error'], $brokenTableData);
        }

        // Display needs-verification links separately
        $verificationLinks = array_filter($results, fn($r) => $r['needsVerification'] ?? false);
        if (!empty($verificationLinks)) {
            $output->newLine();
            $output->warn('Needs Verification:');

            $verificationTableData = [];
            foreach ($verificationLinks as $result) {
                $reasons = $result['verificationReasons'] ?? [];
                $verificationTableData[] = [
                    'URL' => $this->truncate($result['url'], 60),
                    'Source' => $this->truncate($result['sourcePage'], 40),
                    'Element' => '<' . ($result['sourceElement'] ?? 'a') . '>',
                    'Status' => $this->formatStatus($result),
                    'Reason' => implode('|', $reasons),
                ];
            }

            $output->table(['URL', 'Source', 'Element', 'Status', 'Reason'], $verificationTableData);
        }
    }

    /**
     * Build the JSON output array from results and config.
     *
     * Returns the structured array with summary, results, and broken links.
     * Useful for storing scan output in the database without an OutputInterface.
     *
     * @param array $results The scan results.
     * @param ScanConfig $config The scan configuration.
     * @param string|null $error Optional error message (e.g., rate limit abort).
     * @return array{summary: array, results: array, brokenLinks: array, error?: string}
     */
    public function toJsonArray(array $results, ScanConfig $config, ?string $error = null): array
    {
        // Remove noise URLs unless --advanced is used
        if (!$config->showAdvanced) {
            $results = $this->scanStatistics->filterNoiseUrls($results, config('scanner.noise_urls', []));
        }

        $filtered = $this->scanStatistics->filterResults($results, $config->statusFilter);
        $filtered = $this->scanStatistics->filterByElement($filtered, $config->elementFilter);

        $stats = $this->scanStatistics->calculateStats($filtered);
        $totalScanned = count($results);
        $isFiltered = $config->hasFilter();

        $brokenLinks = array_values(array_filter($filtered, fn($r) => !$r['isOk']));

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
        $brokenLinks = array_values(array_filter($results, fn($r) => !$r['isOk']));

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

        $output->line('URL,Source,Element,Status,Type,Redirects,IsOk,HttpsDowngrade,NeedsVerification,VerificationReasons');

        foreach ($results as $result) {
            $redirects = implode(' -> ', $result['redirectChain']);
            $isOk = $result['isOk'] ? 'true' : 'false';
            $httpsDowngrade = ($result['hasHttpsDowngrade'] ?? false) ? 'true' : 'false';
            $element = $result['sourceElement'] ?? 'a';
            $needsVerification = ($result['needsVerification'] ?? false) ? 'true' : 'false';
            $verificationReasons = implode('|', $result['verificationReasons'] ?? []);

            $output->line(sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $result['url']),
                str_replace('"', '""', $result['sourcePage']),
                $element,
                $result['status'],
                $result['type'],
                str_replace('"', '""', $redirects),
                $isOk,
                $httpsDowngrade,
                $needsVerification,
                $verificationReasons
            ));
        }
    }

    /**
     * Truncate a string to a maximum length.
     */
    protected function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }

    /**
     * Format a status code for display.
     *
     * For form endpoints that return non-2xx but are considered healthy
     * (e.g., 422 Unprocessable Entity from posting empty data), appends
     * "(ok)" to make it clear the endpoint exists and is functional.
     *
     * For URLs needing verification, appends "(verify)" in table output.
     *
     * @param array $result The scan result item.
     * @param bool $isTableOutput Whether formatting for table (vs JSON/CSV).
     * @return string|int The formatted status for display.
     */
    protected function formatStatus(array $result, bool $isTableOutput = true): string|int
    {
        $status = $result['status'];
        $isOk = $result['isOk'] ?? false;
        $element = $result['sourceElement'] ?? 'a';
        $needsVerification = $result['needsVerification'] ?? false;
        $verificationReasons = $result['verificationReasons'] ?? [];

        // Annotate healthy non-2xx form endpoints so the user knows it's alive
        if ($element === 'form' && $isOk && is_int($status) && ($status < 200 || $status >= 300)) {
            return "{$status} (ok)";
        }

        // Annotate URLs needing verification
        if ($needsVerification && $isTableOutput) {
            // For bot protection (403/405/Error/Timeout) and suspicious URLs (any status),
            // always show annotation
            if (in_array(VerificationReason::BotProtection->value, $verificationReasons, true) ||
                in_array(VerificationReason::IndirectReference->value, $verificationReasons, true) ||
                in_array(VerificationReason::DeveloperLeftover->value, $verificationReasons, true)) {
                return "{$status} (verify)";
            }

            // For JS bundle extracted, only show annotation for 200 status
            if ($status === 200 && in_array(VerificationReason::JsBundleExtracted->value, $verificationReasons, true)) {
                return "{$status} (verify)";
            }
        }

        return $status;
    }
}

