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
            scanElements: ['a', 'link', 'script', 'img', 'media'],
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
            scanElements: ['a', 'link', 'script', 'img', 'media'],
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
            scanElements: ['a', 'link', 'script', 'img', 'media'],
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

        $this->assertEquals(['a', 'link', 'script', 'img', 'media'], $result['config']->scanElements);
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

