<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the LinkExtractor service.
 *
 * Provides static access to HTML link extraction functionality.
 *
 * @method static array extractLinks(string $html, string $sourceUrl, bool $scanScriptContent = false) Extract all links from HTML content.
 *
 * @see \App\Services\LinkExtractor
 */
class Links extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'link-extractor';
    }
}

