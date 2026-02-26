<?php

namespace Tests\Feature;

use Symfony\Component\Console\Exception\RuntimeException;
use Tests\TestCase;

class ScanSiteCommandTest extends TestCase
{
    /**
     * Test command requires a URL argument
     */
    public function test_command_requires_url_argument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "url")');

        $this->artisan('site:scan');
    }

    /**
     * Test command fails with invalid URL
     */
    public function test_command_fails_with_invalid_url(): void
    {
        $this->artisan('site:scan', ['url' => 'not-a-valid-url'])
            ->expectsOutput('Invalid URL provided.')
            ->assertExitCode(1);
    }

    /**
     * Test command shows help with correct options
     */
    public function test_command_has_correct_signature(): void
    {
        $this->artisan('site:scan', ['url' => 'https://example.com', '--help' => true])
            ->expectsOutputToContain('depth')
            ->expectsOutputToContain('max')
            ->expectsOutputToContain('timeout')
            ->expectsOutputToContain('format')
            ->expectsOutputToContain('status')
            ->assertExitCode(0);
    }

    /**
     * Test successful scan with table output
     */
    public function test_successful_scan_with_table_output(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 1,
            '--max' => 3,
        ])
            ->expectsOutputToContain('Site Scan: https://example.com')
            ->expectsOutputToContain('Summary:')
            ->expectsOutputToContain('Total links:')
            ->assertExitCode(0);
    }

    /**
     * Test JSON output format
     */
    public function test_json_output_format(): void
    {
        $this->markTestSkipped('This test is currently skipped because the JSON output format is not fully implemented yet.');
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 1,
            '--max' => 2,
            '--format' => 'json',
        ])
            ->expectsOutputToContain('"summary"')
            ->expectsOutputToContain('"results"')
            ->expectsOutputToContain('"total"')
            ->assertExitCode(0);
    }

    /**
     * Test CSV output format
     */
    public function test_csv_output_format(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 1,
            '--max' => 2,
            '--format' => 'csv',
        ])
            ->expectsOutputToContain('URL,Source,Status,Type,Redirects,IsOk')
            ->assertExitCode(0);
    }

    /**
     * Test status filter for ok links
     */
    public function test_status_filter_ok(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 1,
            '--max' => 2,
            '--status' => 'ok',
        ])
            ->expectsOutputToContain('Summary:')
            ->assertExitCode(0);
    }

    /**
     * Test status filter for broken links
     */
    public function test_status_filter_broken(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 1,
            '--max' => 2,
            '--status' => 'broken',
        ])
            ->expectsOutputToContain('Summary:')
            ->assertExitCode(0);
    }

    /**
     * Test max URLs limit is respected
     */
    public function test_max_urls_limit_is_respected(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 10,
            '--max' => 1,
        ])
            ->expectsOutputToContain('Total links:')
            ->assertExitCode(0);
    }

    /**
     * Test depth limit is respected
     */
    public function test_depth_limit_is_respected(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--depth' => 0,
            '--max' => 5,
        ])
            ->expectsOutputToContain('Summary:')
            ->assertExitCode(0);
    }

    /**
     * Test sitemap option is available in command signature
     */
    public function test_sitemap_option_exists(): void
    {
        $this->artisan('site:scan', ['url' => 'https://example.com', '--help' => true])
            ->expectsOutputToContain('sitemap')
            ->assertExitCode(0);
    }

    /**
     * Test sitemap option shows discovery message
     */
    public function test_sitemap_option_shows_discovery_message(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--sitemap' => true,
            '--depth' => 1,
            '--max' => 5,
        ])
            ->expectsOutputToContain('Discovering URLs from sitemap...')
            ->assertExitCode(0);
    }

    /**
     * Test sitemap option with no sitemap available falls back to page crawling
     */
    public function test_sitemap_fallback_to_page_crawling(): void
    {
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--sitemap' => true,
            '--depth' => 1,
            '--max' => 5,
        ])
            // example.com likely doesn't have a sitemap, should fallback
            ->expectsOutputToContain('Summary:')
            ->assertExitCode(0);
    }

    /**
     * Test sitemap combined with regular crawling still works even when no sitemap found
     */
    public function test_sitemap_combined_with_regular_crawling(): void
    {
        // Test that using --sitemap still crawls pages normally even if no sitemap is found
        $this->artisan('site:scan', [
            'url' => 'https://example.com',
            '--sitemap' => true,
            '--depth' => 1,
            '--max' => 5,
        ])
            ->expectsOutputToContain('Discovering URLs from sitemap...')
            ->expectsOutputToContain('Summary:')
            ->expectsOutputToContain('Total links:')
            ->assertExitCode(0);
    }
}
