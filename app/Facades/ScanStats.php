<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the ScanStatistics service.
 *
 * Provides static access to scan result statistics and filtering.
 *
 * @method static array calculateStats(array $results) Calculate statistics from scan results.
 * @method static array filterResults(array $results, string $filter) Filter scan results by status.
 * @method static array filterByElement(array $results, string $element) Filter scan results by source element type.
 *
 * @see \App\Services\ScanStatistics
 */
class ScanStats extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'scan-stats';
    }
}

