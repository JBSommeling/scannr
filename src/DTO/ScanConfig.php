<?php

namespace Scannr\DTO;

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
        public bool $useJsRendering = false,
        public bool $useSmartJs = false,
        public bool $respectRobots = true,
        public bool $showAdvanced = false,
        public bool $failOnCritical = false,
        public string $minRating = 'none',
    ) {}

    /**
     * Create a ScanConfig from a plain associative array.
     *
     * Applies the same hard-cap logic as fromCommandOptions.
     *
     * @return array{config: self, warnings: array<string>}
     */
    public static function fromArray(array $data): array
    {
        $warnings = [];
        $baseUrl = rtrim($data['baseUrl'] ?? $data['url'] ?? '', '/');

        // Parse and cap depth
        $maxDepth = (int) ($data['maxDepth'] ?? 3);
        $hardMaxDepth = config('scannr.hard_max_depth', 10);
        if ($maxDepth > $hardMaxDepth) {
            $warnings[] = "Depth {$maxDepth} exceeds hard limit, capping to {$hardMaxDepth}";
            $maxDepth = $hardMaxDepth;
        }

        // Parse and cap max URLs
        $maxUrls = (int) ($data['maxUrls'] ?? 300);
        $hardMaxUrls = config('scannr.hard_max_urls', 2000);
        if ($maxUrls > $hardMaxUrls) {
            $warnings[] = "Max URLs {$maxUrls} exceeds hard limit, capping to {$hardMaxUrls}";
            $maxUrls = $hardMaxUrls;
        }

        // Parse and cap timeout
        $requestedTimeout = (int) ($data['timeout'] ?? 5);
        $maxTimeout = config('scannr.timeout', 30);
        $timeout = min($requestedTimeout, $maxTimeout);

        // Parse scan elements
        $scanElements = $data['scanElements'] ?? ['a', 'link', 'script', 'img', 'media', 'form'];

        // Get rate limiting config (user-provided values override config defaults)
        $configDelayMin = config('scannr.request_delay_min', 300);
        $configDelayMax = config('scannr.request_delay_max', 500);
        $delayMin = ($data['delayMin'] ?? null) !== null ? (int) $data['delayMin'] : $configDelayMin;
        $delayMax = ($data['delayMax'] ?? null) !== null ? (int) $data['delayMax'] : $configDelayMax;

        // Ensure delayMax is at least delayMin
        if ($delayMax < $delayMin) {
            $delayMax = $delayMin;
        }

        $useJsRendering = (bool) ($data['useJsRendering'] ?? false);
        // --js takes precedence: when full JS rendering is on, smart-js is unnecessary
        $useSmartJs = $useJsRendering ? false : (bool) ($data['useSmartJs'] ?? false);

        $config = new self(
            baseUrl: $baseUrl,
            maxDepth: $maxDepth,
            maxUrls: $maxUrls,
            timeout: $timeout,
            scanElements: $scanElements,
            statusFilter: $data['statusFilter'] ?? 'all',
            elementFilter: $data['elementFilter'] ?? 'all',
            outputFormat: $data['outputFormat'] ?? 'json',
            delayMin: $delayMin,
            delayMax: $delayMax,
            useSitemap: (bool) ($data['useSitemap'] ?? false),
            customTrackingParams: $data['customTrackingParams'] ?? [],
            useJsRendering: $useJsRendering,
            useSmartJs: $useSmartJs,
            respectRobots: (bool) ($data['respectRobots'] ?? true),
            showAdvanced: (bool) ($data['showAdvanced'] ?? false),
            failOnCritical: (bool) ($data['failOnCritical'] ?? false),
            minRating: $data['minRating'] ?? 'none',
        );

        return ['config' => $config, 'warnings' => $warnings];
    }

    /**
     * Create a ScanConfig from command options.
     *
     * Parses command arguments/options into an array and delegates to fromArray.
     *
     * @return array{config: self, warnings: array<string>}
     */
    public static function fromCommandOptions(Command $command): array
    {
        // Parse scan elements
        $scanElementsOption = $command->option('scan-elements');
        $scanElements = $scanElementsOption === 'all'
            ? ['a', 'link', 'script', 'img', 'media', 'form']
            : array_map('trim', explode(',', $scanElementsOption));

        // Parse custom tracking params
        $stripParams = $command->option('strip-params');
        $customTrackingParams = $stripParams
            ? array_map('trim', explode(',', $stripParams))
            : [];

        return self::fromArray([
            'baseUrl' => $command->argument('url'),
            'maxDepth' => (int) $command->option('depth'),
            'maxUrls' => (int) $command->option('max'),
            'timeout' => (int) $command->option('timeout'),
            'scanElements' => $scanElements,
            'statusFilter' => $command->option('status'),
            'elementFilter' => $command->option('filter'),
            'outputFormat' => $command->option('format'),
            'delayMin' => $command->option('delay-min') !== null ? (int) $command->option('delay-min') : null,
            'delayMax' => $command->option('delay-max') !== null ? (int) $command->option('delay-max') : null,
            'useSitemap' => (bool) $command->option('sitemap'),
            'customTrackingParams' => $customTrackingParams,
            'useJsRendering' => (bool) $command->option('js'),
            'useSmartJs' => (bool) $command->option('smart-js'),
            'respectRobots' => ! $command->option('no-robots'),
            'showAdvanced' => (bool) $command->option('advanced'),
            'failOnCritical' => (bool) $command->option('fail-on-critical'),
            'minRating' => $command->option('min-rating'),
        ]);
    }

    /**
     * Serialize the config to a plain array.
     *
     * Can be stored as JSON and later restored via fromArray().
     */
    public function toArray(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'maxDepth' => $this->maxDepth,
            'maxUrls' => $this->maxUrls,
            'timeout' => $this->timeout,
            'scanElements' => $this->scanElements,
            'statusFilter' => $this->statusFilter,
            'elementFilter' => $this->elementFilter,
            'outputFormat' => $this->outputFormat,
            'delayMin' => $this->delayMin,
            'delayMax' => $this->delayMax,
            'useSitemap' => $this->useSitemap,
            'customTrackingParams' => $this->customTrackingParams,
            'useJsRendering' => $this->useJsRendering,
            'useSmartJs' => $this->useSmartJs,
            'respectRobots' => $this->respectRobots,
            'showAdvanced' => $this->showAdvanced,
            'failOnCritical' => $this->failOnCritical,
            'minRating' => $this->minRating,
        ];
    }

    /**
     * Check if any filter is applied (display or scan-level).
     */
    public function hasFilter(): bool
    {
        return $this->hasDisplayFilter()
            || $this->scanElements !== ['a', 'link', 'script', 'img', 'media', 'form'];
    }

    /**
     * Check if a display-only filter is applied (--status or --filter).
     *
     * Does not include --scan-elements since that restricts what gets scanned,
     * not what gets displayed from already-scanned results.
     */
    public function hasDisplayFilter(): bool
    {
        return $this->statusFilter !== 'all'
            || $this->elementFilter !== 'all';
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
