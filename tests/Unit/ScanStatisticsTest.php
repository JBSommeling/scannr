<?php

namespace Tests\Unit;

use App\Services\ScanStatistics;
use PHPUnit\Framework\TestCase;

class ScanStatisticsTest extends TestCase
{
    private ScanStatistics $scanStatistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanStatistics = new ScanStatistics();
    }
    // =====================
    // filterResults tests
    // =====================

    public function test_filter_results_returns_all_for_all_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->scanStatistics->filterResults($results, 'all');
        $this->assertCount(3, $result);
    }

    public function test_filter_results_returns_only_ok_for_ok_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->scanStatistics->filterResults($results, 'ok');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertTrue($item['isOk']);
        }
    }

    public function test_filter_results_returns_only_broken_for_broken_filter(): void
    {
        $results = [
            ['isOk' => true, 'url' => 'https://example.com/1'],
            ['isOk' => false, 'url' => 'https://example.com/2'],
            ['isOk' => true, 'url' => 'https://example.com/3'],
        ];

        $result = $this->scanStatistics->filterResults($results, 'broken');
        $this->assertCount(1, $result);
        foreach ($result as $item) {
            $this->assertFalse($item['isOk']);
        }
    }

    public function test_filter_results_handles_empty_results(): void
    {
        $result = $this->scanStatistics->filterResults([], 'all');
        $this->assertCount(0, $result);
    }
    // ======================
    // filterByElement tests
    // ======================

    public function test_filter_by_element_returns_all_for_all_filter(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'all');
        $this->assertCount(4, $result);
    }

    public function test_filter_by_element_returns_only_anchors(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'a');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('a', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_images(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'a'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'link'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'img');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('img', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_scripts(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'script'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'script');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('script', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_returns_only_links(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'link'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'link'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'link');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('link', $item['sourceElement']);
        }
    }

    public function test_filter_by_element_handles_missing_source_element(): void
    {
        $results = [
            ['url' => 'https://example.com/1'],  // No sourceElement, defaults to 'a'
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'a'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'a');
        $this->assertCount(2, $result);
    }

    public function test_filter_by_element_handles_empty_results(): void
    {
        $result = $this->scanStatistics->filterByElement([], 'img');
        $this->assertCount(0, $result);
    }

    // ======================
    // calculateStats tests
    // ======================

    public function test_calculate_stats_counts_correctly(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'type' => 'internal', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'type' => 'internal', 'redirectChain' => ['https://example.com/redirect'], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 404, 'type' => 'internal', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 500, 'type' => 'internal', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
            ['isOk' => false, 'status' => 'Timeout', 'type' => 'internal', 'redirectChain' => [], 'hasHttpsDowngrade' => false],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(1, $stats['ok']);        // 200 without redirects
        $this->assertEquals(1, $stats['redirects']); // 200 with redirects
        $this->assertEquals(2, $stats['broken']);    // 404 + 500
        $this->assertEquals(1, $stats['timeouts']);  // Timeout
        $this->assertEquals(0, $stats['redirectChainCount']); // single redirect is not a chain
        $this->assertEquals(1, $stats['totalRedirectHops']); // 1 hop
        $this->assertEquals(0, $stats['httpsDowngrades']); // no downgrades
    }

    public function test_calculate_stats_handles_empty_results(): void
    {
        $stats = $this->scanStatistics->calculateStats([]);

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
        $results = [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://a.com', 'https://b.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://c.com'], 'hasHttpsDowngrade' => false],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(0, $stats['ok']);
        $this->assertEquals(2, $stats['redirects']);
        $this->assertEquals(1, $stats['redirectChainCount']); // only 1 has 2+ hops
        $this->assertEquals(3, $stats['totalRedirectHops']); // 2 + 1 hops
    }

    public function test_calculate_stats_counts_https_downgrades(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://example.com'], 'hasHttpsDowngrade' => true],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['https://example.com'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'redirectChain' => ['http://other.com'], 'hasHttpsDowngrade' => true],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['httpsDowngrades']);
        $this->assertEquals(0, $stats['redirectChainCount']);
        $this->assertEquals(3, $stats['totalRedirectHops']);
    }

    public function test_calculate_stats_excludes_external_redirect_chains(): void
    {
        $results = [
            ['isOk' => true, 'status' => 200, 'type' => 'internal', 'redirectChain' => ['https://example.com/a', 'https://example.com/b'], 'hasHttpsDowngrade' => false],
            ['isOk' => true, 'status' => 200, 'type' => 'external', 'redirectChain' => ['https://external.com/redirect'], 'hasHttpsDowngrade' => false],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        // Only the internal chain should be counted
        $this->assertEquals(1, $stats['redirectChainCount']);
        $this->assertEquals(2, $stats['totalRedirectHops']);
    }

    public function test_filter_by_element_returns_only_media(): void
    {
        $results = [
            ['url' => 'https://example.com/1', 'sourceElement' => 'media'],
            ['url' => 'https://example.com/2', 'sourceElement' => 'img'],
            ['url' => 'https://example.com/3', 'sourceElement' => 'media'],
            ['url' => 'https://example.com/4', 'sourceElement' => 'a'],
        ];

        $result = $this->scanStatistics->filterByElement($results, 'media');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('media', $item['sourceElement']);
        }
    }

    // =========================
    // filterNoiseUrls tests
    // =========================

    private function getNoisePatterns(): array
    {
        return [
            'exact' => [
                'https://fonts.googleapis.com',
                'https://fonts.gstatic.com',
                'https://fonts.bunny.net',
            ],
            'prefix' => [
                'http://www.w3.org/2000/svg',
                'http://www.w3.org/1998/Math/MathML',
                'http://www.w3.org/1999/xlink',
                'http://www.w3.org/XML/1998/namespace',
                'https://www.w3.org/2000/svg',
                'https://www.w3.org/1998/Math/MathML',
                'https://www.w3.org/1999/xlink',
                'https://www.w3.org/XML/1998/namespace',
                'https://schema.org',
                'http://schema.org',
                'https://react.dev/errors',
                'https://reactjs.org/docs/error',
                'https://vuejs.org/error-reference',
            ],
        ];
    }

    public function test_filter_noise_urls_removes_xml_namespace_urls(): void
    {
        $results = [
            ['url' => 'http://www.w3.org/2000/svg', 'isOk' => true],
            ['url' => 'http://www.w3.org/1998/Math/MathML', 'isOk' => true],
            ['url' => 'http://www.w3.org/1999/xlink', 'isOk' => true],
            ['url' => 'http://www.w3.org/XML/1998/namespace', 'isOk' => true],
            ['url' => 'https://schema.org', 'isOk' => true],
            ['url' => 'https://example.com/page1', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_removes_cdn_root_domains_exact_only(): void
    {
        $results = [
            ['url' => 'https://fonts.googleapis.com', 'isOk' => false],
            ['url' => 'https://fonts.gstatic.com', 'isOk' => false],
            ['url' => 'https://fonts.bunny.net', 'isOk' => true],
            // These should NOT be filtered (they have paths — real resources)
            ['url' => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono', 'isOk' => true],
            ['url' => 'https://fonts.bunny.net/css?family=inter', 'isOk' => true],
            ['url' => 'https://example.com/page1', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(3, $filtered);

        $urls = array_column(array_values($filtered), 'url');
        $this->assertContains('https://fonts.googleapis.com/css2?family=JetBrains+Mono', $urls);
        $this->assertContains('https://fonts.bunny.net/css?family=inter', $urls);
        $this->assertContains('https://example.com/page1', $urls);
    }

    public function test_filter_noise_urls_removes_js_framework_error_docs(): void
    {
        $results = [
            ['url' => 'https://react.dev/errors', 'isOk' => true],
            ['url' => 'https://react.dev/errors/123', 'isOk' => true],
            ['url' => 'https://vuejs.org/error-reference', 'isOk' => true],
            ['url' => 'https://vuejs.org/error-reference#runtime-errors', 'isOk' => true],
            ['url' => 'https://example.com/page1', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_keeps_normal_urls(): void
    {
        $results = [
            ['url' => 'https://example.com', 'isOk' => true],
            ['url' => 'https://example.com/page1', 'isOk' => true],
            ['url' => 'https://github.com/user', 'isOk' => true],
            ['url' => 'https://linkedin.com/in/user', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(4, $filtered);
    }

    public function test_filter_noise_urls_handles_empty_results(): void
    {
        $filtered = $this->scanStatistics->filterNoiseUrls([], $this->getNoisePatterns());
        $this->assertCount(0, $filtered);
    }

    public function test_filter_noise_urls_removes_https_variant_of_xml_namespaces(): void
    {
        $results = [
            ['url' => 'https://www.w3.org/2000/svg', 'isOk' => true],
            ['url' => 'https://www.w3.org/1998/Math/MathML', 'isOk' => true],
            ['url' => 'https://www.w3.org/1999/xlink', 'isOk' => true],
            ['url' => 'https://www.w3.org/XML/1998/namespace', 'isOk' => true],
            ['url' => 'http://schema.org', 'isOk' => true],
            ['url' => 'https://example.com/page1', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_passes_through_with_empty_patterns(): void
    {
        $results = [
            ['url' => 'http://www.w3.org/2000/svg', 'isOk' => true],
            ['url' => 'https://fonts.googleapis.com', 'isOk' => false],
            ['url' => 'https://example.com/page1', 'isOk' => true],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, ['exact' => [], 'prefix' => []]);
        $this->assertCount(3, $filtered);
    }

}
