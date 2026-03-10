<?php

namespace Tests\Unit;

use App\Services\ScanStatistics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScanStatisticsTest extends TestCase
{
    private ScanStatistics $scanStatistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanStatistics = new ScanStatistics;
    }
    // =====================
    // filterResults tests
    // =====================

    public function test_filter_results_returns_all_for_all_filter(): void
    {
        $results = [
            ['status' => '200', 'url' => 'https://example.com/1', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '404', 'url' => 'https://example.com/2', 'analysis' => ['flags' => ['status_4xx'], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'url' => 'https://example.com/3', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $result = $this->scanStatistics->filterResults($results, 'all');
        $this->assertCount(3, $result);
    }

    public function test_filter_results_returns_only_ok_for_ok_filter(): void
    {
        $results = [
            ['status' => '200', 'url' => 'https://example.com/1', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '404', 'url' => 'https://example.com/2', 'analysis' => ['flags' => ['status_4xx'], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'url' => 'https://example.com/3', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $result = $this->scanStatistics->filterResults($results, 'ok');
        $this->assertCount(2, $result);
        foreach ($result as $item) {
            $this->assertEquals('200', $item['status']);
        }
    }

    public function test_filter_results_returns_only_broken_for_broken_filter(): void
    {
        $results = [
            ['status' => '200', 'url' => 'https://example.com/1', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '404', 'url' => 'https://example.com/2', 'analysis' => ['flags' => ['status_4xx'], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'url' => 'https://example.com/3', 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $result = $this->scanStatistics->filterResults($results, 'broken');
        $this->assertCount(1, $result);
        foreach ($result as $item) {
            $this->assertContains('status_4xx', $item['analysis']['flags']);
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
            ['status' => '200', 'type' => 'internal', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'type' => 'internal', 'redirect' => ['chain' => ['https://example.com/redirect'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '404', 'type' => 'internal', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['status_4xx'], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '500', 'type' => 'internal', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['status_5xx'], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => 'timeout', 'type' => 'internal', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['timeout'], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(1, $stats['ok']);        // 200 without redirects
        $this->assertEquals(1, $stats['redirects']); // 200 with redirects
        $this->assertEquals(3, $stats['broken']);    // 404 + 500 + timeout
        $this->assertEquals(1, $stats['timeouts']);  // timeout (still separately tracked)
        $this->assertEquals(0, $stats['redirectChainCount']); // single redirect is not a chain
        $this->assertEquals(1, $stats['totalRedirectHops']); // 1 hop
        $this->assertEquals(0, $stats['httpsDowngrades']); // no downgrades
        $this->assertEquals(0, $stats['pagesScanned']); // no sourcePage in test data
        $this->assertEquals(5, $stats['internalLinks']); // all internal, default element 'a'
        $this->assertEquals(0, $stats['assetsScanned']); // no non-anchor elements
        $this->assertEquals(0, $stats['externalLinks']); // all internal
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
            ['status' => '200', 'redirect' => ['chain' => ['https://a.com', 'https://b.com'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'redirect' => ['chain' => ['https://c.com'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
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
            ['status' => '200', 'redirect' => ['chain' => ['http://example.com'], 'isLoop' => false, 'hasHttpsDowngrade' => true], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'redirect' => ['chain' => ['https://example.com'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'redirect' => ['chain' => ['http://other.com'], 'isLoop' => false, 'hasHttpsDowngrade' => true], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
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
            ['status' => '200', 'type' => 'internal', 'redirect' => ['chain' => ['https://example.com/a', 'https://example.com/b'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'type' => 'external', 'redirect' => ['chain' => ['https://external.com/redirect'], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
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
            'namespace_domains' => [
                'www.w3.org',
                'w3.org',
                'schema.org',
                'www.schema.org',
            ],
            'detect_preconnect' => true,
            'framework_error_patterns' => [
                '#^https?://react\.dev/errors#',
                '#^https?://reactjs\.org/docs/error#',
                '#^https?://vuejs\.org/error-reference#',
                '#^https?://angular\.(io|dev)/errors#',
                '#^https?://svelte\.dev/e/#',
                '#^https?://nextjs\.org/docs/messages/#',
            ],
            'exact' => [],
            'prefix' => [],
        ];
    }

    public function test_filter_noise_urls_removes_xml_namespace_urls(): void
    {
        $results = [
            ['url' => 'http://www.w3.org/2000/svg', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'http://www.w3.org/1998/Math/MathML', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'http://www.w3.org/1999/xlink', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'http://www.w3.org/XML/1998/namespace', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://schema.org', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_detects_preconnect_bare_domains(): void
    {
        $results = [
            // Bare domains in <link> — preconnect hints, should be filtered
            ['url' => 'https://fonts.googleapis.com', 'isOk' => false, 'sourceElement' => 'link', 'type' => 'external'],
            ['url' => 'https://fonts.gstatic.com', 'isOk' => false, 'sourceElement' => 'link', 'type' => 'external'],
            ['url' => 'https://fonts.bunny.net', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            ['url' => 'https://cdn.example.com/', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            // CDN with path — real resource, should NOT be filtered
            ['url' => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            ['url' => 'https://fonts.bunny.net/css?family=inter', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            // Bare domain in <a> — not preconnect, should NOT be filtered
            ['url' => 'https://some-cdn.example.com', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(4, $filtered);

        $urls = array_column(array_values($filtered), 'url');
        $this->assertContains('https://fonts.googleapis.com/css2?family=JetBrains+Mono', $urls);
        $this->assertContains('https://fonts.bunny.net/css?family=inter', $urls);
        $this->assertContains('https://some-cdn.example.com', $urls);
        $this->assertContains('https://example.com/page1', $urls);
    }

    public function test_filter_noise_urls_removes_js_framework_error_docs(): void
    {
        $results = [
            ['url' => 'https://react.dev/errors', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://react.dev/errors/123', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://vuejs.org/error-reference', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://vuejs.org/error-reference#runtime-errors', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://angular.dev/errors/NG0100', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://svelte.dev/e/some-error', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://nextjs.org/docs/messages/some-error', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_keeps_normal_urls(): void
    {
        $results = [
            ['url' => 'https://example.com', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
            ['url' => 'https://github.com/user', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://linkedin.com/in/user', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
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
            ['url' => 'https://www.w3.org/2000/svg', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://www.w3.org/1998/Math/MathML', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://www.w3.org/1999/xlink', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://www.w3.org/XML/1998/namespace', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'http://schema.org', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_filter_noise_urls_passes_through_with_empty_patterns(): void
    {
        $results = [
            ['url' => 'http://www.w3.org/2000/svg', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://fonts.googleapis.com', 'isOk' => false, 'sourceElement' => 'link', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $emptyPatterns = ['namespace_domains' => [], 'detect_preconnect' => false, 'framework_error_patterns' => [], 'exact' => [], 'prefix' => []];
        $filtered = $this->scanStatistics->filterNoiseUrls($results, $emptyPatterns);
        $this->assertCount(3, $filtered);
    }

    public function test_filter_noise_urls_preconnect_only_filters_external_link_elements(): void
    {
        $results = [
            // <link> bare external domain — filtered (preconnect hint)
            ['url' => 'https://cdn.example.com', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            // <a> bare external domain — NOT filtered (could be a real link)
            ['url' => 'https://other-cdn.example.com', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            // <link> with path — NOT filtered (real resource)
            ['url' => 'https://cdn.example.com/style.css', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'external'],
            // internal <link> bare domain — NOT filtered (preconnect is only external)
            ['url' => 'https://example.com', 'isOk' => true, 'sourceElement' => 'link', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $this->getNoisePatterns());
        $this->assertCount(3, $filtered);

        $filteredUrls = array_map(fn ($r) => $r['url'].'|'.$r['sourceElement'], array_values($filtered));
        $this->assertContains('https://other-cdn.example.com|a', $filteredUrls);
        $this->assertContains('https://cdn.example.com/style.css|link', $filteredUrls);
        $this->assertContains('https://example.com|link', $filteredUrls);
    }

    public function test_filter_noise_urls_exact_and_prefix_fallback(): void
    {
        $patterns = [
            'namespace_domains' => [],
            'detect_preconnect' => false,
            'framework_error_patterns' => [],
            'exact' => ['https://custom-noise.example.com'],
            'prefix' => ['https://another-noise.example.com/docs'],
        ];

        $results = [
            ['url' => 'https://custom-noise.example.com', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://another-noise.example.com/docs/something', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'external'],
            ['url' => 'https://example.com/page1', 'isOk' => true, 'sourceElement' => 'a', 'type' => 'internal'],
        ];

        $filtered = $this->scanStatistics->filterNoiseUrls($results, $patterns);
        $this->assertCount(1, $filtered);
        $this->assertEquals('https://example.com/page1', array_values($filtered)[0]['url']);
    }

    public function test_calculate_stats_includes_low_confidence_count(): void
    {
        $results = [
            ['status' => '200', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['detected_in_js_bundle'], 'confidence' => 'low', 'verification' => 'recommended']],
            ['status' => '200', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['indirect_reference'], 'confidence' => 'low', 'verification' => 'recommended']],
            ['status' => '200', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '403', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['bot_protection'], 'confidence' => 'low', 'verification' => 'recommended']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(3, $stats['lowConfidenceCount']);
    }

    public function test_calculate_stats_low_confidence_count_zero_when_all_high(): void
    {
        $results = [
            ['status' => '200', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['status' => '200', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(0, $stats['lowConfidenceCount']);
    }

    // =============================================
    // Form endpoint broken/healthy classification
    // =============================================

    private function makeFormEndpointResult(string $status): array
    {
        return [
            'url' => 'https://app.example.com/api/contacts',
            'sourcePage' => 'https://example.com',
            'status' => $status,
            'type' => 'internal',
            'sourceElement' => 'form',
            'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false],
            'analysis' => ['flags' => ['form_endpoint', 'status_4xx'], 'confidence' => 'high', 'verification' => 'none'],
            'network' => ['retryAfter' => null],
        ];
    }

    public function test_form_endpoint_404_is_counted_as_broken(): void
    {
        $results = [$this->makeFormEndpointResult('404')];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(1, $stats['broken']);
    }

    public function test_form_endpoint_500_is_counted_as_broken(): void
    {
        $result = $this->makeFormEndpointResult('500');
        $result['analysis']['flags'] = ['form_endpoint', 'status_5xx'];
        $results = [$result];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(1, $stats['broken']);
    }

    public function test_form_endpoint_503_is_counted_as_broken(): void
    {
        $result = $this->makeFormEndpointResult('503');
        $result['analysis']['flags'] = ['form_endpoint', 'status_5xx'];
        $results = [$result];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(1, $stats['broken']);
    }

    #[DataProvider('healthyFormEndpointStatusProvider')]
    public function test_form_endpoint_healthy_statuses_not_counted_as_broken(string $status): void
    {
        $results = [$this->makeFormEndpointResult($status)];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(0, $stats['broken']);
    }

    public static function healthyFormEndpointStatusProvider(): array
    {
        return [
            '400 Bad Request' => ['400'],
            '401 Unauthorized' => ['401'],
            '403 Forbidden' => ['403'],
            '405 Method Not Allowed' => ['405'],
            '422 Unprocessable Entity' => ['422'],
            '429 Too Many Requests' => ['429'],
        ];
    }

    public function test_filter_broken_excludes_healthy_form_endpoints(): void
    {
        $results = [$this->makeFormEndpointResult('422')];

        $filtered = $this->scanStatistics->filterResults($results, 'broken');

        $this->assertEmpty($filtered);
    }

    public function test_filter_broken_includes_404_form_endpoint(): void
    {
        $results = [$this->makeFormEndpointResult('404')];

        $filtered = $this->scanStatistics->filterResults($results, 'broken');

        $this->assertCount(1, $filtered);
    }

    public function test_filter_broken_includes_500_form_endpoint(): void
    {
        $result = $this->makeFormEndpointResult('500');
        $result['analysis']['flags'] = ['form_endpoint', 'status_5xx'];
        $results = [$result];

        $filtered = $this->scanStatistics->filterResults($results, 'broken');

        $this->assertCount(1, $filtered);
    }

    // =============================================
    // Broken link status classification
    // =============================================

    private function makeSimpleResult(string $status, array $flags = []): array
    {
        return [
            'url' => 'https://example.com/page',
            'sourcePage' => 'https://example.com',
            'status' => $status,
            'type' => 'external',
            'sourceElement' => 'a',
            'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false],
            'analysis' => ['flags' => $flags, 'confidence' => 'high', 'verification' => 'none'],
            'network' => ['retryAfter' => null],
        ];
    }

    #[DataProvider('brokenStatusProvider')]
    public function test_status_counts_as_broken(string $status, array $flags): void
    {
        $stats = $this->scanStatistics->calculateStats([$this->makeSimpleResult($status, $flags)]);

        $this->assertEquals(1, $stats['broken'], "Expected status '{$status}' to count as broken");
    }

    public static function brokenStatusProvider(): array
    {
        return [
            '404 Not Found'        => ['404', ['status_4xx']],
            '410 Gone'             => ['410', ['status_4xx']],
            '500 Server Error'     => ['500', ['status_5xx']],
            '503 Unavailable'      => ['503', ['status_5xx']],
            'timeout'              => ['timeout', ['timeout']],
            'error (connection)'   => ['error', ['connection_error']],
        ];
    }

    #[DataProvider('notBrokenStatusProvider')]
    public function test_status_does_not_count_as_broken(string $status, array $flags): void
    {
        $stats = $this->scanStatistics->calculateStats([$this->makeSimpleResult($status, $flags)]);

        $this->assertEquals(0, $stats['broken'], "Expected status '{$status}' NOT to count as broken");
    }

    public static function notBrokenStatusProvider(): array
    {
        return [
            '200 OK'              => ['200', []],
            '201 Created'         => ['201', []],
            '204 No Content'      => ['204', []],
            'empty status'        => ['', []],
            '403 bot protected'   => ['403', ['bot_protection']],
            '405 bot protected'   => ['405', ['bot_protection', 'external_platform']],
        ];
    }

    public function test_timeout_counted_in_both_broken_and_timeouts(): void
    {
        $stats = $this->scanStatistics->calculateStats([
            $this->makeSimpleResult('timeout', ['timeout']),
        ]);

        $this->assertEquals(1, $stats['broken']);
        $this->assertEquals(1, $stats['timeouts']);
    }

    // ======================
    // isBrokenResult tests
    // ======================

    public function test_is_broken_result_404(): void
    {
        $this->assertTrue($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('404', ['status_4xx'])
        ));
    }

    public function test_is_broken_result_200_is_not_broken(): void
    {
        $this->assertFalse($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('200', [])
        ));
    }

    public function test_is_broken_result_empty_status_is_not_broken(): void
    {
        $this->assertFalse($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('', [])
        ));
    }

    public function test_is_broken_result_bot_protected_is_not_broken(): void
    {
        $this->assertFalse($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('403', ['bot_protection', 'external_platform'])
        ));
    }

    public function test_is_broken_result_healthy_form_is_not_broken(): void
    {
        $this->assertFalse($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('405', ['form_endpoint'])
        ));
    }

    public function test_is_broken_result_timeout_is_broken(): void
    {
        $this->assertTrue($this->scanStatistics->isBrokenResult(
            $this->makeSimpleResult('timeout', ['timeout'])
        ));
    }

    // ======================
    // filterResults('broken') consistency
    // ======================

    public function test_filter_results_broken_excludes_bot_protected(): void
    {
        $results = [
            $this->makeSimpleResult('404', ['status_4xx']),
            $this->makeSimpleResult('403', ['bot_protection', 'external_platform']),
            $this->makeSimpleResult('200', []),
        ];

        $filtered = $this->scanStatistics->filterResults($results, 'broken');

        $this->assertCount(1, $filtered);
        $this->assertEquals('404', array_values($filtered)[0]['status']);
    }

    public function test_filter_results_broken_excludes_empty_status(): void
    {
        $results = [
            $this->makeSimpleResult('', []),
            $this->makeSimpleResult('404', ['status_4xx']),
        ];

        $filtered = $this->scanStatistics->filterResults($results, 'broken');

        $this->assertCount(1, $filtered);
        $this->assertEquals('404', array_values($filtered)[0]['status']);
    }

    public function test_calculate_stats_counts_pages_scanned(): void
    {
        $results = [
            ['url' => 'https://example.com/a', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://example.com/b', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://example.com/c', 'sourcePage' => 'https://example.com/about', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(2, $stats['pagesScanned']);
    }

    public function test_calculate_stats_counts_assets_scanned(): void
    {
        $results = [
            ['url' => 'https://example.com/style.css', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'link', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://example.com/app.js', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'script', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://example.com/logo.png', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'img', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://example.com/about', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://cdn.example.com/font.woff', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'external', 'sourceElement' => 'link', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        // 3 internal non-anchor elements (link, script, img); anchor and external link excluded
        $this->assertEquals(3, $stats['assetsScanned']);
        // 1 internal <a> element
        $this->assertEquals(1, $stats['internalLinks']);
        // 1 external element
        $this->assertEquals(1, $stats['externalLinks']);
        // All three sum to total
        $this->assertEquals($stats['total'], $stats['internalLinks'] + $stats['assetsScanned'] + $stats['externalLinks']);
    }

    public function test_calculate_stats_counts_external_links(): void
    {
        $results = [
            ['url' => 'https://example.com/a', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://twitter.com/test', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'external', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
            ['url' => 'https://linkedin.com/in/test', 'sourcePage' => 'https://example.com/', 'status' => '405', 'type' => 'external', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => ['bot_protection'], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertEquals(2, $stats['externalLinks']);
    }

    public function test_calculate_stats_granular_counts_in_existing_stats(): void
    {
        $results = [
            ['url' => 'https://example.com/page', 'sourcePage' => 'https://example.com/', 'status' => '200', 'type' => 'internal', 'sourceElement' => 'a', 'redirect' => ['chain' => [], 'isLoop' => false, 'hasHttpsDowngrade' => false], 'analysis' => ['flags' => [], 'confidence' => 'high', 'verification' => 'none']],
        ];

        $stats = $this->scanStatistics->calculateStats($results);

        $this->assertArrayHasKey('pagesScanned', $stats);
        $this->assertArrayHasKey('internalLinks', $stats);
        $this->assertArrayHasKey('assetsScanned', $stats);
        $this->assertArrayHasKey('externalLinks', $stats);
    }
}
