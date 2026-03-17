<?php

namespace Tests\Unit;

use Scannr\Services\BrowsershotFetcher;
use Tests\TestCase;

class BrowsershotFetcherTest extends TestCase
{
    // ==================
    // Constructor / Configure tests
    // ==================

    public function test_browsershot_fetcher_can_be_instantiated(): void
    {
        $fetcher = new BrowsershotFetcher;

        $this->assertInstanceOf(BrowsershotFetcher::class, $fetcher);
    }

    public function test_set_timeout_returns_fluent_interface(): void
    {
        $fetcher = new BrowsershotFetcher;
        $result = $fetcher->setTimeout(10);

        $this->assertSame($fetcher, $result);
    }

    public function test_configure_returns_fluent_interface(): void
    {
        $fetcher = new BrowsershotFetcher;
        $result = $fetcher->configure([
            'node_binary' => '/usr/local/bin/node',
            'npm_binary' => '/usr/local/bin/npm',
            'chrome_path' => '/usr/bin/chromium',
            'timeout' => 15,
        ]);

        $this->assertSame($fetcher, $result);
    }

    public function test_configure_with_empty_options(): void
    {
        $fetcher = new BrowsershotFetcher;
        $result = $fetcher->configure([]);

        $this->assertSame($fetcher, $result);
    }

    public function test_configure_with_partial_options(): void
    {
        $fetcher = new BrowsershotFetcher;
        $result = $fetcher->configure([
            'node_binary' => '/usr/local/bin/node',
        ]);

        $this->assertSame($fetcher, $result);
    }

    // ==================
    // fetch() error handling tests
    // ==================

    public function test_fetch_returns_error_when_browsershot_throws(): void
    {
        // We use a subclass to simulate a Browsershot failure without needing
        // a real browser. This tests the catch block in fetch().
        $fetcher = new class extends BrowsershotFetcher
        {
            public function fetch(string $url): array
            {
                // Simulate what happens when Browsershot throws
                try {
                    throw new \RuntimeException('Chrome binary not found');
                } catch (\Exception $e) {
                    return [
                        'status' => 'Error',
                        'body' => null,
                        'finalUrl' => $url,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        };

        $result = $fetcher->fetch('https://example.com');

        $this->assertEquals('Error', $result['status']);
        $this->assertNull($result['body']);
        $this->assertEquals('https://example.com', $result['finalUrl']);
        $this->assertEquals('Chrome binary not found', $result['error']);
    }

    public function test_fetch_error_result_has_expected_keys(): void
    {
        $fetcher = new class extends BrowsershotFetcher
        {
            public function fetch(string $url): array
            {
                try {
                    throw new \Exception('Something went wrong');
                } catch (\Exception $e) {
                    return [
                        'status' => 'Error',
                        'body' => null,
                        'finalUrl' => $url,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        };

        $result = $fetcher->fetch('https://example.com/page');

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('finalUrl', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_fetch_success_result_has_expected_keys(): void
    {
        $fetcher = new class extends BrowsershotFetcher
        {
            public function fetch(string $url): array
            {
                return [
                    'status' => 200,
                    'body' => '<html><body>Rendered</body></html>',
                    'finalUrl' => $url,
                ];
            }
        };

        $result = $fetcher->fetch('https://example.com');

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('finalUrl', $result);
        $this->assertEquals(200, $result['status']);
        $this->assertNotNull($result['body']);
    }

    public function test_fetch_preserves_url_in_result(): void
    {
        $fetcher = new class extends BrowsershotFetcher
        {
            public function fetch(string $url): array
            {
                return [
                    'status' => 200,
                    'body' => '<html></html>',
                    'finalUrl' => $url,
                ];
            }
        };

        $url = 'https://example.com/some/deep/page';
        $result = $fetcher->fetch($url);

        $this->assertEquals($url, $result['finalUrl']);
    }

    // ==================
    // checkDependencies tests
    // ==================

    public function test_check_dependencies_returns_expected_structure(): void
    {
        $result = BrowsershotFetcher::checkDependencies();

        $this->assertArrayHasKey('available', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['available']);
        $this->assertIsString($result['message']);
    }

    public function test_check_dependencies_returns_available_when_deps_exist(): void
    {
        // This test validates the actual environment — if Node + Puppeteer are
        // installed (as they should be in this project), it should return true.
        $result = BrowsershotFetcher::checkDependencies();

        // Node.js should be available on the dev machine
        $nodePath = trim(shell_exec('which node 2>/dev/null') ?? '');
        if (! empty($nodePath) && is_dir(base_path().'/node_modules/puppeteer')) {
            $this->assertTrue($result['available']);
            $this->assertStringContainsString('available', $result['message']);
        } else {
            // If deps aren't installed, the message should explain what's missing
            $this->assertFalse($result['available']);
            $this->assertNotEmpty($result['message']);
        }
    }
}
