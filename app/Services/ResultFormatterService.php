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
        protected ScannerService $scannerService,
    ) {}

    /**
     * Format and display results based on configuration.
     *
     * @param array $results The scan results.
     * @param ScanConfig $config The scan configuration.
     * @param OutputInterface $output The output interface.
     */
    public function format(array $results, ScanConfig $config, OutputInterface $output): void
    {
        // Filter results
        $filtered = $this->scannerService->filterResults($results, $config->statusFilter);
        $filtered = $this->scannerService->filterByElement($filtered, $config->elementFilter);

        // Calculate stats
        $stats = $this->scannerService->calculateStats($filtered);
        $totalScanned = count($results);
        $isFiltered = $config->hasFilter();

        // Display based on format
        match ($config->outputFormat) {
            'json' => $this->displayJson($filtered, $stats, $totalScanned, $isFiltered, $output),
            'csv' => $this->displayCsv($filtered, $output),
            default => $this->displayTable($filtered, $stats, $totalScanned, $isFiltered, $output),
        };
    }

    /**
     * Display results as a table.
     */
    protected function displayTable(array $results, array $stats, int $totalScanned, bool $isFiltered, OutputInterface $output): void
    {
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

        // Broken links alert
        if ($stats['broken'] > 0) {
            $output->error("  ⚠ Broken links: {$stats['broken']}");
        }

        if ($stats['redirectChainCount'] > 0 || $stats['httpsDowngrades'] > 0 || $stats['broken'] > 0) {
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
                'Status' => $result['status'],
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
    }

    /**
     * Build the JSON output array from results and config.
     *
     * Returns the structured array with summary, results, and broken links.
     * Useful for storing scan output in the database without an OutputInterface.
     *
     * @param array $results The scan results.
     * @param ScanConfig $config The scan configuration.
     * @return array{summary: array, results: array, brokenLinks: array}
     */
    public function toJsonArray(array $results, ScanConfig $config): array
    {
        $filtered = $this->scannerService->filterResults($results, $config->statusFilter);
        $filtered = $this->scannerService->filterByElement($filtered, $config->elementFilter);

        $stats = $this->scannerService->calculateStats($filtered);
        $totalScanned = count($results);
        $isFiltered = $config->hasFilter();

        $brokenLinks = array_values(array_filter($filtered, fn($r) => !$r['isOk']));

        $summary = ['totalScanned' => $totalScanned];

        if ($isFiltered) {
            $summary['filtered'] = $stats['total'];
        }

        $statsWithoutTotal = array_diff_key($stats, ['total' => true]);
        $summary = array_merge($summary, $statsWithoutTotal);

        return [
            'summary' => $summary,
            'results' => array_values($filtered),
            'brokenLinks' => $brokenLinks,
        ];
    }

    /**
     * Display results as JSON.
     */
    protected function displayJson(array $results, array $stats, int $totalScanned, bool $isFiltered, OutputInterface $output): void
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

        $output->line(json_encode($jsonOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Display results as CSV.
     */
    protected function displayCsv(array $results, OutputInterface $output): void
    {
        $output->line('URL,Source,Element,Status,Type,Redirects,IsOk,HttpsDowngrade');

        foreach ($results as $result) {
            $redirects = implode(' -> ', $result['redirectChain']);
            $isOk = $result['isOk'] ? 'true' : 'false';
            $httpsDowngrade = ($result['hasHttpsDowngrade'] ?? false) ? 'true' : 'false';
            $element = $result['sourceElement'] ?? 'a';

            $output->line(sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $result['url']),
                str_replace('"', '""', $result['sourcePage']),
                $element,
                $result['status'],
                $result['type'],
                str_replace('"', '""', $redirects),
                $isOk,
                $httpsDowngrade
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
}

