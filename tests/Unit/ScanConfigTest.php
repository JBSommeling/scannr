<?php

namespace Tests\Unit;

use App\DTO\ScanConfig;
use Illuminate\Console\Command;
use Tests\TestCase;

class ScanConfigTest extends TestCase
{
    // ==================
    // Constructor tests
    // ==================

    public function test_scan_config_can_be_instantiated(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'img'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertInstanceOf(ScanConfig::class, $config);
    }

    public function test_scan_config_properties_are_accessible(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 5,
            maxUrls: 200,
            timeout: 10,
            scanElements: ['a', 'link', 'script', 'img'],
            statusFilter: 'broken',
            elementFilter: 'a',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: true,
            customTrackingParams: ['ref', 'tracker'],
        );

        $this->assertEquals('https://example.com', $config->baseUrl);
        $this->assertEquals(5, $config->maxDepth);
        $this->assertEquals(200, $config->maxUrls);
        $this->assertEquals(10, $config->timeout);
        $this->assertEquals(['a', 'link', 'script', 'img'], $config->scanElements);
        $this->assertEquals('broken', $config->statusFilter);
        $this->assertEquals('a', $config->elementFilter);
        $this->assertEquals('json', $config->outputFormat);
        $this->assertEquals(100, $config->delayMin);
        $this->assertEquals(200, $config->delayMax);
        $this->assertTrue($config->useSitemap);
        $this->assertEquals(['ref', 'tracker'], $config->customTrackingParams);
    }

    public function test_scan_config_is_readonly(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $reflection = new \ReflectionClass($config);
        $this->assertTrue($reflection->isReadOnly());
    }

    // ==================
    // getBaseHost tests
    // ==================

    public function test_get_base_host_extracts_host_from_url(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://www.example.com/path',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertEquals('www.example.com', $config->getBaseHost());
    }

    public function test_get_base_host_handles_port(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com:8080',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertEquals('example.com', $config->getBaseHost());
    }

    public function test_get_base_host_returns_empty_for_invalid_url(): void
    {
        $config = new ScanConfig(
            baseUrl: 'not-a-valid-url',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertEquals('', $config->getBaseHost());
    }

    // ==================
    // hasFilter tests
    // ==================

    public function test_has_filter_returns_false_when_no_filter(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertFalse($config->hasFilter());
    }

    public function test_has_filter_returns_true_when_status_filter_applied(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: 'broken',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertTrue($config->hasFilter());
    }

    public function test_has_filter_returns_true_when_element_filter_applied(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: 'all',
            elementFilter: 'a',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertTrue($config->hasFilter());
    }

    public function test_has_filter_returns_true_when_scan_elements_limited(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'img'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertTrue($config->hasFilter());
    }

    // ==================
    // fromCommandOptions tests (using mock)
    // ==================

    public function test_from_command_options_creates_config(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com/',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);
        $config = $result['config'];

        $this->assertInstanceOf(ScanConfig::class, $config);
        $this->assertEquals('https://example.com', $config->baseUrl);
        $this->assertEquals(3, $config->maxDepth);
    }

    public function test_from_command_options_parses_scan_elements_all(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertEquals(['a', 'link', 'script', 'img', 'media', 'form'], $result['config']->scanElements);
    }

    public function test_from_command_options_parses_scan_elements_comma_separated(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'a,img',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertEquals(['a', 'img'], $result['config']->scanElements);
    }

    public function test_from_command_options_parses_custom_tracking_params(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => 'ref,tracker_*',
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertEquals(['ref', 'tracker_*'], $result['config']->customTrackingParams);
    }

    public function test_from_command_options_returns_warnings_for_depth(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '999', // Exceeds hard limit
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertArrayHasKey('config', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Depth', $result['warnings'][0]);
    }

    public function test_from_command_options_caps_max_urls(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '99999', // Exceeds hard limit
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Max URLs', $result['warnings'][0]);
    }

    public function test_from_command_options_strips_trailing_slash(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com/',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertEquals('https://example.com', $result['config']->baseUrl);
    }

    // ==================
    // useJsRendering tests
    // ==================

    public function test_scan_config_defaults_use_js_rendering_to_false(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertFalse($config->useJsRendering);
    }

    public function test_scan_config_use_js_rendering_can_be_enabled(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            useJsRendering: true,
        );

        $this->assertTrue($config->useJsRendering);
    }

    // ==================
    // useSmartJs tests
    // ==================

    public function test_scan_config_defaults_use_smart_js_to_false(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertFalse($config->useSmartJs);
    }

    public function test_scan_config_use_smart_js_can_be_enabled(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            useSmartJs: true,
        );

        $this->assertTrue($config->useSmartJs);
    }

    public function test_scan_config_js_takes_precedence_over_smart_js(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'useJsRendering' => true,
            'useSmartJs' => true,
        ]);

        $config = $result['config'];
        $this->assertTrue($config->useJsRendering);
        $this->assertFalse($config->useSmartJs);
    }

    public function test_scan_config_smart_js_from_array(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'useSmartJs' => true,
        ]);

        $config = $result['config'];
        $this->assertFalse($config->useJsRendering);
        $this->assertTrue($config->useSmartJs);
    }

    public function test_scan_config_smart_js_to_array(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            useSmartJs: true,
        );

        $array = $config->toArray();
        $this->assertTrue($array['useSmartJs']);
    }

    // ==================
    // showAdvanced tests
    // ==================

    public function test_scan_config_defaults_show_advanced_to_false(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
        );

        $this->assertFalse($config->showAdvanced);
    }

    public function test_scan_config_show_advanced_can_be_enabled(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            showAdvanced: true,
        );

        $this->assertTrue($config->showAdvanced);
    }

    public function test_from_command_options_advanced_flag_disabled_by_default(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'advanced' => false,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertFalse($result['config']->showAdvanced);
    }

    public function test_from_command_options_advanced_flag_enabled(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'advanced' => true,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertTrue($result['config']->showAdvanced);
    }

    public function test_has_filter_not_affected_by_show_advanced(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 3,
            maxUrls: 100,
            timeout: 5,
            scanElements: ['a', 'link', 'script', 'img', 'media', 'form'],
            statusFilter: 'all',
            elementFilter: 'all',
            outputFormat: 'table',
            delayMin: 300,
            delayMax: 500,
            useSitemap: false,
            customTrackingParams: [],
            showAdvanced: true,
        );

        $this->assertFalse($config->hasFilter());
    }

    public function test_from_command_options_js_flag_disabled_by_default(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'js' => false,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertFalse($result['config']->useJsRendering);
    }

    public function test_from_command_options_js_flag_enabled(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'js' => true,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertTrue($result['config']->useJsRendering);
    }

    public function test_from_command_options_smart_js_flag_disabled_by_default(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'js' => false,
            'smart-js' => false,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertFalse($result['config']->useSmartJs);
    }

    public function test_from_command_options_smart_js_flag_enabled(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'js' => false,
            'smart-js' => true,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertTrue($result['config']->useSmartJs);
    }

    public function test_from_command_options_js_takes_precedence_over_smart_js(): void
    {
        $command = $this->createMockCommand([
            'url' => 'https://example.com',
            'depth' => '3',
            'max' => '100',
            'timeout' => '5',
            'format' => 'table',
            'status' => 'all',
            'filter' => 'all',
            'scan-elements' => 'all',
            'sitemap' => false,
            'strip-params' => null,
            'js' => true,
            'smart-js' => true,
        ]);

        $result = ScanConfig::fromCommandOptions($command);

        $this->assertTrue($result['config']->useJsRendering);
        $this->assertFalse($result['config']->useSmartJs, '--js should take precedence over --smart-js');
    }

    // ==================
    // fromArray tests
    // ==================

    public function test_from_array_creates_config_with_defaults(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com/',
        ]);

        $config = $result['config'];

        $this->assertInstanceOf(ScanConfig::class, $config);
        $this->assertEquals('https://example.com', $config->baseUrl);
        $this->assertEquals(3, $config->maxDepth);
        $this->assertEquals(300, $config->maxUrls);
        $this->assertEquals(5, $config->timeout);
        $this->assertEquals(['a', 'link', 'script', 'img', 'media', 'form'], $config->scanElements);
        $this->assertEquals('all', $config->statusFilter);
        $this->assertEquals('all', $config->elementFilter);
        $this->assertEquals('json', $config->outputFormat);
        $this->assertFalse($config->useSitemap);
        $this->assertEmpty($config->customTrackingParams);
        $this->assertFalse($config->useJsRendering);
        $this->assertFalse($config->useSmartJs);
        $this->assertTrue($config->respectRobots);
        $this->assertFalse($config->showAdvanced);
        $this->assertEmpty($result['warnings']);
    }

    public function test_from_array_accepts_url_key(): void
    {
        $result = ScanConfig::fromArray(['url' => 'https://example.com']);
        $this->assertEquals('https://example.com', $result['config']->baseUrl);
    }

    public function test_from_array_applies_all_provided_values(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'maxDepth' => 5,
            'maxUrls' => 200,
            'timeout' => 10,
            'scanElements' => ['a', 'img'],
            'statusFilter' => 'broken',
            'elementFilter' => 'a',
            'outputFormat' => 'json',
            'useSitemap' => true,
            'customTrackingParams' => ['ref'],
            'useJsRendering' => true,
            'respectRobots' => false,
        ]);

        $config = $result['config'];

        $this->assertEquals(5, $config->maxDepth);
        $this->assertEquals(200, $config->maxUrls);
        $this->assertEquals(['a', 'img'], $config->scanElements);
        $this->assertEquals('broken', $config->statusFilter);
        $this->assertEquals('a', $config->elementFilter);
        $this->assertTrue($config->useSitemap);
        $this->assertEquals(['ref'], $config->customTrackingParams);
        $this->assertTrue($config->useJsRendering);
        $this->assertFalse($config->respectRobots);
    }

    public function test_from_array_caps_depth_exceeding_hard_limit(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'maxDepth' => 999,
        ]);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Depth', $result['warnings'][0]);
        $this->assertLessThanOrEqual(config('scanner.hard_max_depth', 10), $result['config']->maxDepth);
    }

    public function test_from_array_caps_max_urls_exceeding_hard_limit(): void
    {
        $result = ScanConfig::fromArray([
            'baseUrl' => 'https://example.com',
            'maxUrls' => 99999,
        ]);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Max URLs', $result['warnings'][0]);
        $this->assertLessThanOrEqual(config('scanner.hard_max_urls', 2000), $result['config']->maxUrls);
    }

    // ==================
    // toArray tests
    // ==================

    public function test_to_array_returns_all_properties(): void
    {
        $config = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 5,
            maxUrls: 200,
            timeout: 10,
            scanElements: ['a', 'img'],
            statusFilter: 'broken',
            elementFilter: 'a',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: true,
            customTrackingParams: ['ref'],
            useJsRendering: true,
            respectRobots: false,
        );

        $array = $config->toArray();

        $this->assertEquals('https://example.com', $array['baseUrl']);
        $this->assertEquals(5, $array['maxDepth']);
        $this->assertEquals(200, $array['maxUrls']);
        $this->assertEquals(10, $array['timeout']);
        $this->assertEquals(['a', 'img'], $array['scanElements']);
        $this->assertEquals('broken', $array['statusFilter']);
        $this->assertEquals('a', $array['elementFilter']);
        $this->assertEquals('json', $array['outputFormat']);
        $this->assertEquals(100, $array['delayMin']);
        $this->assertEquals(200, $array['delayMax']);
        $this->assertTrue($array['useSitemap']);
        $this->assertEquals(['ref'], $array['customTrackingParams']);
        $this->assertTrue($array['useJsRendering']);
        $this->assertFalse($array['respectRobots']);
        $this->assertFalse($array['showAdvanced']);
    }

    // ==================
    // fromArray / toArray round-trip tests
    // ==================

    public function test_from_array_to_array_round_trip(): void
    {
        $original = new ScanConfig(
            baseUrl: 'https://example.com',
            maxDepth: 5,
            maxUrls: 200,
            timeout: 10,
            scanElements: ['a', 'img'],
            statusFilter: 'broken',
            elementFilter: 'a',
            outputFormat: 'json',
            delayMin: 100,
            delayMax: 200,
            useSitemap: true,
            customTrackingParams: ['ref', 'tracker'],
            useJsRendering: true,
            respectRobots: false,
        );

        $array = $original->toArray();
        $restored = ScanConfig::fromArray($array)['config'];

        $this->assertEquals($original->baseUrl, $restored->baseUrl);
        $this->assertEquals($original->maxDepth, $restored->maxDepth);
        $this->assertEquals($original->maxUrls, $restored->maxUrls);
        $this->assertEquals($original->scanElements, $restored->scanElements);
        $this->assertEquals($original->statusFilter, $restored->statusFilter);
        $this->assertEquals($original->elementFilter, $restored->elementFilter);
        $this->assertEquals($original->outputFormat, $restored->outputFormat);
        $this->assertEquals($original->useSitemap, $restored->useSitemap);
        $this->assertEquals($original->customTrackingParams, $restored->customTrackingParams);
        $this->assertEquals($original->useJsRendering, $restored->useJsRendering);
        $this->assertEquals($original->respectRobots, $restored->respectRobots);
        $this->assertEquals($original->showAdvanced, $restored->showAdvanced);
    }

    private function createMockCommand(array $options): Command
    {
        $command = $this->createMock(Command::class);

        $command->method('argument')
            ->willReturnCallback(function ($name) use ($options) {
                return $options[$name] ?? null;
            });

        $command->method('option')
            ->willReturnCallback(function ($name) use ($options) {
                return $options[$name] ?? null;
            });

        return $command;
    }
}
