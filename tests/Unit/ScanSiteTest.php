<?php

namespace Tests\Unit;

use App\Console\Commands\ScanSite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ScanSiteTest extends TestCase
{
    private ScanSite $command;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ScanSite();
        $this->reflection = new ReflectionClass($this->command);
    }

    private function invokeMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        return $method->invokeArgs($this->command, $parameters);
    }

    private function setProperty(string $propertyName, mixed $value): void
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setValue($this->command, $value);
    }

    // ===================
    // normalizeUrl tests
    // ===================

    public function test_normalize_url_returns_null_for_empty_string(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['', 'https://example.com']);
        $this->assertNull($result);
    }

    public function test_normalize_url_returns_null_for_null(): void
    {
        $result = $this->invokeMethod('normalizeUrl', [null, 'https://example.com']);
        $this->assertNull($result);
    }

    public function test_normalize_url_removes_fragment(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['https://example.com/page#section', 'https://example.com']);
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_returns_null_for_fragment_only(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['#section', 'https://example.com']);
        $this->assertNull($result);
    }

    public function test_normalize_url_handles_protocol_relative_urls(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['//cdn.example.com/script.js', 'https://example.com']);
        $this->assertEquals('https://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_absolute_urls(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['https://other.com/page', 'https://example.com']);
        $this->assertEquals('https://other.com/page', $result);
    }

    public function test_normalize_url_handles_absolute_path(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['/about', 'https://example.com/page']);
        $this->assertEquals('https://example.com/about', $result);
    }

    public function test_normalize_url_handles_relative_path(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['contact', 'https://example.com/pages/about']);
        $this->assertEquals('https://example.com/pages/contact', $result);
    }

    public function test_normalize_url_removes_trailing_slash(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['https://example.com/page/', 'https://example.com']);
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_preserves_port(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['/page', 'https://example.com:8080']);
        $this->assertEquals('https://example.com:8080/page', $result);
    }

    public function test_normalize_url_handles_http_protocol(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['//cdn.example.com/script.js', 'http://example.com']);
        $this->assertEquals('http://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_query_string(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['/search?q=test', 'https://example.com']);
        $this->assertEquals('https://example.com/search?q=test', $result);
    }

    public function test_normalize_url_removes_fragment_but_keeps_query(): void
    {
        $result = $this->invokeMethod('normalizeUrl', ['/page?foo=bar#section', 'https://example.com']);
        $this->assertEquals('https://example.com/page?foo=bar', $result);
    }

    // ====================
    // isInternalUrl tests
    // ====================

    public function test_is_internal_url_returns_true_for_same_host(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://example.com/page']);
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_subdomain(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://www.example.com/page']);
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_deep_subdomain(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://blog.www.example.com/page']);
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_false_for_different_host(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://other.com/page']);
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_false_for_similar_domain(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://notexample.com/page']);
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_true_for_relative_url(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['/page']);
        $this->assertTrue($result);
    }

    public function test_is_internal_url_handles_different_ports(): void
    {
        $this->setProperty('baseHost', 'example.com');
        $result = $this->invokeMethod('isInternalUrl', ['https://example.com:8080/page']);
        $this->assertTrue($result);
    }

    // =====================
    // filterResults tests
    // =====================

    public function test_filter_results_returns_all_for_all_filter(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ]);

        $result = $this->invokeMethod('filterResults', ['all']);
        $this->assertCount(3, $result);
    }

    public function test_filter_results_returns_only_ok_for_ok_filter(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ]);

        $result = $this->invokeMethod('filterResults', ['ok']);
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertTrue($item['isOk']);
        }
    }

    public function test_filter_results_returns_only_broken_for_broken_filter(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ]);

        $result = $this->invokeMethod('filterResults', ['broken']);
        $this->assertCount(1, $result);
        foreach ($result as $item) {
            $this->assertFalse($item['isOk']);
        }
    }

    public function test_filter_results_handles_empty_results(): void
    {
        $this->setProperty('results', []);

        $result = $this->invokeMethod('filterResults', ['all']);
        $this->assertCount(0, $result);
    }

    // ======================
    // calculateStats tests
    // ======================

    public function test_calculate_stats_counts_correctly(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'status' => 200, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://example.com/redirect'], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 404, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 500, 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 'Timeout', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
        ]);

        $stats = $this->invokeMethod('calculateStats', []);

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(1, $stats['ok']);        // 200 without redirects
        $this->assertEquals(1, $stats['redirects']); // 200 with redirects
        $this->assertEquals(2, $stats['broken']);    // 404 + 500
        $this->assertEquals(1, $stats['timeouts']);  // Timeout
        $this->assertEquals(1, $stats['redirectChainCount']); // 1 chain
        $this->assertEquals(1, $stats['totalRedirectHops']); // 1 hop
        $this->assertEquals(0, $stats['httpsDowngrades']); // no downgrades
    }

    public function test_calculate_stats_handles_empty_results(): void
    {
        $this->setProperty('results', []);

        $stats = $this->invokeMethod('calculateStats', []);

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['ok']);
        $this->assertEquals(0, $stats['redirects']);
        $this->assertEquals(0, $stats['broken']);
        $this->assertEquals(0, $stats['timeouts']);
        $this->assertEquals(0, $stats['redirectChainCount']);
        $this->assertEquals(0, $stats['totalRedirectHops']);
        $this->assertEquals(0, $stats['httpsDowngrades']);
    }

    public function test_calculate_stats_counts_multiple_redirects(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://a.com', 'https://b.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://c.com'], 'hasHttpsDowngrade' => false],
        ]);

        $stats = $this->invokeMethod('calculateStats', []);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(0, $stats['ok']);
        $this->assertEquals(2, $stats['redirects']);
        $this->assertEquals(2, $stats['redirectChainCount']); // 2 chains
        $this->assertEquals(3, $stats['totalRedirectHops']); // 2 + 1 hops
    }

    public function test_calculate_stats_counts_https_downgrades(): void
    {
        $this->setProperty('results', [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://example.com'], 'hasHttpsDowngrade' => true],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://example.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://other.com'], 'hasHttpsDowngrade' => true],
        ]);

        $stats = $this->invokeMethod('calculateStats', []);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['httpsDowngrades']);
        $this->assertEquals(3, $stats['redirectChainCount']);
        $this->assertEquals(3, $stats['totalRedirectHops']);
    }

    // ==================
    // truncate tests
    // ==================

    public function test_truncate_returns_full_string_if_under_length(): void
    {
        $result = $this->invokeMethod('truncate', ['short', 10]);
        $this->assertEquals('short', $result);
    }

    public function test_truncate_returns_exact_length_string(): void
    {
        $result = $this->invokeMethod('truncate', ['exactly10!', 10]);
        $this->assertEquals('exactly10!', $result);
    }

    public function test_truncate_truncates_long_string_with_ellipsis(): void
    {
        $result = $this->invokeMethod('truncate', ['this is a very long string', 10]);
        $this->assertEquals('this is...', $result);
        $this->assertEquals(10, strlen($result));
    }

    public function test_truncate_handles_minimum_length(): void
    {
        $result = $this->invokeMethod('truncate', ['hello', 3]);
        $this->assertEquals('...', $result);
    }

    public function test_truncate_handles_empty_string(): void
    {
        $result = $this->invokeMethod('truncate', ['', 10]);
        $this->assertEquals('', $result);
    }
}
