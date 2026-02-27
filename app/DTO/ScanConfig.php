<?php

namespace App\DTO;

use Illuminate\Console\Command;

/**
 * Data Transfer Object for scan configuration.
 *
 * Encapsulates all parameters needed for a website scan operation.
 */
readonly class ScanConfig
{
    public function __construct(
        public string $baseUrl,
        public int $maxDepth,
        public int $maxUrls,
        public int $timeout,
        public array $scanElements,
        public string $statusFilter,
        public string $elementFilter,
        public string $outputFormat,
        public int $delayMin,
        public int $delayMax,
        public bool $useSitemap,
        public array $customTrackingParams,
    ) {}

    /**
     * Create a ScanConfig from command options.
     *
     * Applies hard caps from configuration and parses all options.
     */
    public static function fromCommandOptions(Command $command, array $warnings = []): self
    {
        $baseUrl = rtrim($command->argument('url'), '/');

        // Parse and cap depth
        $maxDepth = (int) $command->option('depth');
        $hardMaxDepth = config('scanner.hard_max_depth', 10);
        if ($maxDepth > $hardMaxDepth) {
            $warnings[] = "Depth {$maxDepth} exceeds hard limit, capping to {$hardMaxDepth}";
            $maxDepth = $hardMaxDepth;
        }

        // Parse and cap max URLs
        $maxUrls = (int) $command->option('max');
        $hardMaxUrls = config('scanner.hard_max_urls', 2000);
        if ($maxUrls > $hardMaxUrls) {
            $warnings[] = "Max URLs {$maxUrls} exceeds hard limit, capping to {$hardMaxUrls}";
            $maxUrls = $hardMaxUrls;
        }

        // Parse and cap timeout
        $requestedTimeout = (int) $command->option('timeout');
        $maxTimeout = config('scanner.timeout', 30);
        $timeout = min($requestedTimeout, $maxTimeout);

        // Parse scan elements
        $scanElementsOption = $command->option('scan-elements');
        $scanElements = $scanElementsOption === 'all'
            ? ['a', 'link', 'script', 'img']
            : array_map('trim', explode(',', $scanElementsOption));

        // Parse custom tracking params
        $stripParams = $command->option('strip-params');
        $customTrackingParams = $stripParams
            ? array_map('trim', explode(',', $stripParams))
            : [];

        // Get rate limiting config
        $delayMin = config('scanner.request_delay_min', 300);
        $delayMax = config('scanner.request_delay_max', 500);

        return new self(
            baseUrl: $baseUrl,
            maxDepth: $maxDepth,
            maxUrls: $maxUrls,
            timeout: $timeout,
            scanElements: $scanElements,
            statusFilter: $command->option('status'),
            elementFilter: $command->option('filter'),
            outputFormat: $command->option('format'),
            delayMin: $delayMin,
            delayMax: $delayMax,
            useSitemap: (bool) $command->option('sitemap'),
            customTrackingParams: $customTrackingParams,
        );
    }

    /**
     * Create a ScanConfig from command options and collect warnings.
     *
     * @return array{config: self, warnings: array<string>}
     */
    public static function fromCommandOptionsWithWarnings(Command $command): array
    {
        $warnings = [];
        $baseUrl = rtrim($command->argument('url'), '/');

        // Parse and cap depth
        $maxDepth = (int) $command->option('depth');
        $hardMaxDepth = config('scanner.hard_max_depth', 10);
        if ($maxDepth > $hardMaxDepth) {
            $warnings[] = "Depth {$maxDepth} exceeds hard limit, capping to {$hardMaxDepth}";
            $maxDepth = $hardMaxDepth;
        }

        // Parse and cap max URLs
        $maxUrls = (int) $command->option('max');
        $hardMaxUrls = config('scanner.hard_max_urls', 2000);
        if ($maxUrls > $hardMaxUrls) {
            $warnings[] = "Max URLs {$maxUrls} exceeds hard limit, capping to {$hardMaxUrls}";
            $maxUrls = $hardMaxUrls;
        }

        // Parse and cap timeout
        $requestedTimeout = (int) $command->option('timeout');
        $maxTimeout = config('scanner.timeout', 30);
        $timeout = min($requestedTimeout, $maxTimeout);

        // Parse scan elements
        $scanElementsOption = $command->option('scan-elements');
        $scanElements = $scanElementsOption === 'all'
            ? ['a', 'link', 'script', 'img']
            : array_map('trim', explode(',', $scanElementsOption));

        // Parse custom tracking params
        $stripParams = $command->option('strip-params');
        $customTrackingParams = $stripParams
            ? array_map('trim', explode(',', $stripParams))
            : [];

        // Get rate limiting config
        $delayMin = config('scanner.request_delay_min', 300);
        $delayMax = config('scanner.request_delay_max', 500);

        $config = new self(
            baseUrl: $baseUrl,
            maxDepth: $maxDepth,
            maxUrls: $maxUrls,
            timeout: $timeout,
            scanElements: $scanElements,
            statusFilter: $command->option('status'),
            elementFilter: $command->option('filter'),
            outputFormat: $command->option('format'),
            delayMin: $delayMin,
            delayMax: $delayMax,
            useSitemap: (bool) $command->option('sitemap'),
            customTrackingParams: $customTrackingParams,
        );

        return ['config' => $config, 'warnings' => $warnings];
    }

    /**
     * Check if a filter is applied.
     */
    public function hasFilter(): bool
    {
        return $this->statusFilter !== 'all'
            || $this->elementFilter !== 'all'
            || $this->scanElements !== ['a', 'link', 'script', 'img'];
    }

    /**
     * Get the base host from the base URL.
     */
    public function getBaseHost(): string
    {
        $parsed = parse_url($this->baseUrl);
        return $parsed['host'] ?? '';
    }
}

