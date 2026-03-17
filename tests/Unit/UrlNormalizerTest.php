<?php

namespace Tests\Unit;

use Scannr\Services\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $urlNormalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer;
    }
    // ===================
    // normalizeUrl tests
    // ===================

    public function test_normalize_url_returns_null_for_empty_string(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('', 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_returns_null_for_null(): void
    {
        $result = $this->urlNormalizer->normalizeUrl(null, 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_removes_fragment(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('https://example.com/page#section', 'https://example.com');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_returns_null_for_fragment_only(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('#section', 'https://example.com');
        $this->assertNull($result);
    }

    public function test_normalize_url_handles_protocol_relative_urls(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('//cdn.example.com/script.js', 'https://example.com');
        $this->assertEquals('https://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_absolute_urls(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('https://other.com/page', 'https://example.com');
        $this->assertEquals('https://other.com/page', $result);
    }

    public function test_normalize_url_handles_absolute_path(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('/about', 'https://example.com/page');
        $this->assertEquals('https://example.com/about', $result);
    }

    public function test_normalize_url_handles_relative_path(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('contact', 'https://example.com/pages/about');
        $this->assertEquals('https://example.com/pages/contact', $result);
    }

    public function test_normalize_url_removes_trailing_slash(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('https://example.com/page/', 'https://example.com');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_normalize_url_preserves_port(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('/page', 'https://example.com:8080');
        $this->assertEquals('https://example.com:8080/page', $result);
    }

    public function test_normalize_url_handles_http_protocol(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('//cdn.example.com/script.js', 'http://example.com');
        $this->assertEquals('http://cdn.example.com/script.js', $result);
    }

    public function test_normalize_url_handles_query_string(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('/search?q=test', 'https://example.com');
        $this->assertEquals('https://example.com/search?q=test', $result);
    }

    public function test_normalize_url_removes_fragment_but_keeps_query(): void
    {
        $result = $this->urlNormalizer->normalizeUrl('/page?foo=bar#section', 'https://example.com');
        $this->assertEquals('https://example.com/page?foo=bar', $result);
    }
    // ==========================
    // canonicalUrlKey tests
    // ==========================

    public function test_canonical_url_key_strips_trailing_slash(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/page/');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_strips_fragment(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/page#section');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_strips_tracking_params(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/page?utm_source=test&valid=1');
        $this->assertEquals('https://example.com/page?valid=1', $result);
    }

    public function test_canonical_url_key_strips_all_tracking_params(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/page?utm_source=test&fbclid=abc');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_lowercases_host(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://Example.COM/Page');
        $this->assertEquals('https://example.com/Page', $result);
    }

    public function test_canonical_url_key_preserves_path_case(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/About/Contact');
        $this->assertEquals('https://example.com/About/Contact', $result);
    }

    public function test_canonical_url_key_lowercases_scheme_host(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('HTTPS://EXAMPLE.COM/page');
        $this->assertEquals('HTTPS://example.com/page', $result);
    }

    public function test_canonical_url_key_handles_combined_normalization(): void
    {
        // Fragment + trailing slash + tracking params + mixed-case host
        $result = $this->urlNormalizer->canonicalUrlKey('https://Example.COM/page/?utm_source=test&fbclid=abc#section');
        $this->assertEquals('https://example.com/page', $result);
    }

    public function test_canonical_url_key_preserves_port(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://Example.COM:8080/page/');
        $this->assertEquals('https://example.com:8080/page', $result);
    }

    public function test_canonical_url_key_preserves_non_tracking_query_params(): void
    {
        $result = $this->urlNormalizer->canonicalUrlKey('https://example.com/search?q=test&page=2');
        $this->assertEquals('https://example.com/search?q=test&page=2', $result);
    }

    public function test_canonical_url_key_equivalent_urls_produce_same_key(): void
    {
        $key1 = $this->urlNormalizer->canonicalUrlKey('https://Example.com/page/');
        $key2 = $this->urlNormalizer->canonicalUrlKey('https://example.com/page');
        $key3 = $this->urlNormalizer->canonicalUrlKey('https://example.com/page#section');
        $key4 = $this->urlNormalizer->canonicalUrlKey('https://example.com/page?utm_source=google');

        $this->assertEquals($key1, $key2);
        $this->assertEquals($key2, $key3);
        $this->assertEquals($key3, $key4);
    }
    // ====================
    // isInternalUrl tests
    // ====================

    public function test_is_internal_url_returns_true_for_same_host(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_subdomain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://www.example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_true_for_deep_subdomain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://blog.www.example.com/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_returns_false_for_different_host(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://other.com/page');
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_false_for_similar_domain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://notexample.com/page');
        $this->assertFalse($result);
    }

    public function test_is_internal_url_returns_true_for_relative_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('/page');
        $this->assertTrue($result);
    }

    public function test_is_internal_url_handles_different_ports(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');
        $result = $this->urlNormalizer->isInternalUrl('https://example.com:8080/page');
        $this->assertTrue($result);
    }

    // =======================
    // isSubdomainUrl tests
    // =======================

    public function test_is_subdomain_url_returns_true_for_subdomain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertTrue($this->urlNormalizer->isSubdomainUrl('https://yoga-demo.sommeling.dev'));
    }

    public function test_is_subdomain_url_returns_true_for_nested_subdomain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertTrue($this->urlNormalizer->isSubdomainUrl('https://app.demo.sommeling.dev'));
    }

    public function test_is_subdomain_url_returns_false_for_base_host_itself(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertFalse($this->urlNormalizer->isSubdomainUrl('https://sommeling.dev/page'));
    }

    public function test_is_subdomain_url_returns_false_for_www_of_base_host(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertFalse($this->urlNormalizer->isSubdomainUrl('https://www.sommeling.dev/page'));
    }

    public function test_is_subdomain_url_returns_false_for_external_host(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertFalse($this->urlNormalizer->isSubdomainUrl('https://other.dev/page'));
    }

    public function test_is_subdomain_url_returns_false_for_similar_domain(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertFalse($this->urlNormalizer->isSubdomainUrl('https://notsommeling.dev/page'));
    }

    public function test_is_subdomain_url_returns_false_for_relative_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');
        $this->assertFalse($this->urlNormalizer->isSubdomainUrl('/page'));
    }

    // ======================
    // Setter/Getter tests
    // ======================

    public function test_set_and_get_base_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com/path/');

        $this->assertEquals('https://example.com/path', $this->urlNormalizer->getBaseUrl());
        $this->assertEquals('example.com', $this->urlNormalizer->getBaseHost());
    }

    public function test_set_base_url_normalizes_www(): void
    {
        $this->urlNormalizer->setBaseUrl('https://www.example.com/path/');

        // Should normalize to non-www version
        $this->assertEquals('https://example.com/path', $this->urlNormalizer->getBaseUrl());
        $this->assertEquals('example.com', $this->urlNormalizer->getBaseHost());
    }

    public function test_is_internal_url_matches_www_and_non_www(): void
    {
        $this->urlNormalizer->setBaseUrl('https://www.example.com');

        // Both www and non-www should be considered internal
        $this->assertTrue($this->urlNormalizer->isInternalUrl('https://example.com/page'));
        $this->assertTrue($this->urlNormalizer->isInternalUrl('https://www.example.com/page'));
    }

    public function test_is_internal_url_matches_non_www_base_with_www_url(): void
    {
        $this->urlNormalizer->setBaseUrl('https://example.com');

        // www version should also be considered internal
        $this->assertTrue($this->urlNormalizer->isInternalUrl('https://www.example.com/page'));
        $this->assertTrue($this->urlNormalizer->isInternalUrl('https://example.com/page'));
    }
}
