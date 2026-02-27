<?php

namespace Tests\Unit;

use App\Contracts\OutputInterface;
use App\DTO\ScanConfig;
use App\Services\ResultFormatterService;
use App\Services\ScannerService;
use PHPUnit\Framework\TestCase;

class ResultFormatterServiceTest extends TestCase
{
    private ResultFormatterService $formatter;
    private ScannerService $scannerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scannerService = new ScannerService();
        $this->formatter = new ResultFormatterService($this->scannerService);
    }

    private function createConfig(array $overrides = []): ScanConfig
    {
        return new ScanConfig(
            baseUrl: $overrides['baseUrl'] ?? 'https://example.com',
            maxDepth: $overrides['maxDepth'] ?? 3,
            maxUrls: $overrides['maxUrls'] ?? 100,
            timeout: $overrides['timeout'] ?? 5,
            scanElements: $overrides['scanElements'] ?? ['a', 'link', 'script', 'img'],
            statusFilter: $overrides['statusFilter'] ?? 'all',
            elementFilter: $overrides['elementFilter'] ?? 'all',
            outputFormat: $overrides['outputFormat'] ?? 'table',
            delayMin: $overrides['delayMin'] ?? 0,
            delayMax: $overrides['delayMax'] ?? 0,
            useSitemap: $overrides['useSitemap'] ?? false,
            customTrackingParams: $overrides['customTrackingParams'] ?? [],
        );
    }

    private function createMockOutput(): OutputInterface
    {
        return new class implements OutputInterface {
            public array $lines = [];
            public array $infos = [];
            public array $warnings = [];
            public array $errors = [];
            public array $tables = [];
            public int $newLines = 0;
            public bool $verbose = false;

            public function info(string $message): void
            {
                $this->infos[] = $message;
            }

            public function warn(string $message): void
            {
                $this->warnings[] = $message;
            }

            public function error(string $message): void
            {
                $this->errors[] = $message;
            }

            public function line(string $message = ''): void
            {
                $this->lines[] = $message;
            }

            public function table(array $headers, array $rows): void
            {
                $this->tables[] = ['headers' => $headers, 'rows' => $rows];
            }

            public function newLine(int $count = 1): void
            {
                $this->newLines += $count;
            }

            public function isVerbose(): bool
            {
                return $this->verbose;
            }
        };
    }

    private function createSampleResults(): array
    {
        return [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/broken',
                'sourcePage' => 'https://example.com',
                'status' => 404,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/redirect',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/redirected'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];
    }

    // ==================
    // Table format tests
    // ==================

    public function test_format_table_displays_summary(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $this->assertContains('Summary:', $output->infos);
    }

    public function test_format_table_displays_statistics(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        $this->assertStringContainsString('Total scanned:', $lines);
        $this->assertStringContainsString('Working (2xx):', $lines);
        $this->assertStringContainsString('Broken:', $lines);
    }

    public function test_format_table_displays_table(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $this->assertNotEmpty($output->tables);
        $this->assertContains('URL', $output->tables[0]['headers']);
        $this->assertContains('Status', $output->tables[0]['headers']);
    }

    public function test_format_table_shows_broken_links_separately(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $this->assertContains('Broken Links:', $output->errors);
        // Should have 2 tables: main results and broken links
        $this->assertCount(2, $output->tables);
    }

    public function test_format_table_shows_redirect_chain_warning(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        $this->assertStringContainsString('Redirect chains:', $lines);
    }

    public function test_format_table_shows_https_downgrade_warning(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['http://example.com/page1'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => true,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $this->assertNotEmpty($output->warnings);
        $warningText = implode("\n", $output->warnings);
        $this->assertStringContainsString('HTTPS downgrade', $warningText);
    }

    public function test_format_table_shows_no_links_message_when_filtered_empty(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table', 'statusFilter' => 'broken']);
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $this->formatter->format($results, $config, $output);

        $this->assertContains('No links to display for the selected filter.', $output->infos);
    }

    public function test_format_table_includes_element_column(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $this->assertContains('Element', $output->tables[0]['headers']);
    }

    public function test_format_table_verbose_shows_redirects_column(): void
    {
        $output = $this->createMockOutput();
        $output->verbose = true;
        $config = $this->createConfig(['outputFormat' => 'table']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $this->assertContains('Redirects', $output->tables[0]['headers']);
    }

    // ==================
    // JSON format tests
    // ==================

    public function test_format_json_outputs_valid_json(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
    }

    public function test_format_json_includes_summary(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('totalScanned', $decoded['summary']);
    }

    public function test_format_json_includes_results(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(3, $decoded['results']);
    }

    public function test_format_json_includes_broken_links(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('brokenLinks', $decoded);
        $this->assertCount(1, $decoded['brokenLinks']);
    }

    public function test_format_json_shows_filtered_count_when_filtered(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig([
            'outputFormat' => 'json',
            'statusFilter' => 'ok',
        ]);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('filtered', $decoded['summary']);
    }

    // ==================
    // CSV format tests
    // ==================

    public function test_format_csv_outputs_header_row(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $firstLine = $output->lines[0];
        $this->assertStringContainsString('URL', $firstLine);
        $this->assertStringContainsString('Source', $firstLine);
        $this->assertStringContainsString('Status', $firstLine);
    }

    public function test_format_csv_outputs_data_rows(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        // Header + 3 data rows
        $this->assertCount(4, $output->lines);
    }

    public function test_format_csv_escapes_quotes(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page?q="test"',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);

        $this->formatter->format($results, $config, $output);

        // Quotes should be doubled for CSV escaping
        $this->assertStringContainsString('""test""', $output->lines[1]);
    }

    public function test_format_csv_includes_element_column(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $firstLine = $output->lines[0];
        $this->assertStringContainsString('Element', $firstLine);
    }

    // ==================
    // Filter tests
    // ==================

    public function test_format_applies_status_filter(): void
    {
        $output = $this->createMockOutput();
        $config = $this->createConfig([
            'outputFormat' => 'json',
            'statusFilter' => 'broken',
        ]);
        $results = $this->createSampleResults();

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        // Only broken link should be in results
        $this->assertCount(1, $decoded['results']);
        $this->assertEquals(404, $decoded['results'][0]['status']);
    }

    public function test_format_applies_element_filter(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/image.jpg',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'img',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig([
            'outputFormat' => 'json',
            'elementFilter' => 'img',
        ]);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertCount(1, $decoded['results']);
        $this->assertEquals('img', $decoded['results'][0]['sourceElement']);
    }
}

