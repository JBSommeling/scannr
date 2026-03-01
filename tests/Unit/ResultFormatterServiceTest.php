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
            scanElements: $overrides['scanElements'] ?? ['a', 'link', 'script', 'img', 'media', 'form'],
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

    public function test_format_table_form_endpoint_422_shows_ok_annotation(): void
    {
        $results = [
            [
                'url' => 'https://app.example.com/api/contacts',
                'sourcePage' => 'https://example.com',
                'status' => 422,
                'type' => 'external',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'form',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Status should show "422 (ok)" in the table
        $firstRow = $output->tables[0]['rows'][0];
        $this->assertEquals('422 (ok)', $firstRow['Status']);
    }

    public function test_format_table_form_endpoint_200_shows_plain_status(): void
    {
        $results = [
            [
                'url' => 'https://formspree.io/f/abc123',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'external',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'form',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $firstRow = $output->tables[0]['rows'][0];
        $this->assertEquals(200, $firstRow['Status']);
    }

    public function test_format_table_form_endpoint_404_shows_plain_status(): void
    {
        $results = [
            [
                'url' => 'https://app.example.com/api/missing',
                'sourcePage' => 'https://example.com',
                'status' => 404,
                'type' => 'external',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'form',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Broken form endpoint should show plain 404 (no "ok" annotation)
        $firstRow = $output->tables[0]['rows'][0];
        $this->assertEquals(404, $firstRow['Status']);
    }

    public function test_format_table_non_form_422_shows_plain_status(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page',
                'sourcePage' => 'https://example.com',
                'status' => 422,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Non-form 422 should show plain status (no annotation)
        $firstRow = $output->tables[0]['rows'][0];
        $this->assertEquals(422, $firstRow['Status']);
    }

    public function test_format_table_form_endpoint_not_in_broken_links(): void
    {
        $results = [
            [
                'url' => 'https://app.example.com/api/contacts',
                'sourcePage' => 'https://example.com',
                'status' => 422,
                'type' => 'external',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'form',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Should not appear in broken links section
        $this->assertNotContains('Broken Links:', $output->errors);
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

    // ============================
    // Redirect chain and hops tests
    // ============================

    public function test_format_table_displays_redirect_chain_count(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/page2',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/a', 'https://example.com/b', 'https://example.com/c'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        $this->assertStringContainsString('Redirect chains:', $lines);
        $this->assertStringContainsString('2 chains', $lines);
    }

    public function test_format_table_displays_total_redirect_hops(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/page2',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/a', 'https://example.com/b', 'https://example.com/c'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        // 2 hops + 3 hops = 5 total hops
        $this->assertStringContainsString('5 total hops', $lines);
    }

    public function test_format_json_includes_redirect_chain_stats(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('redirectChainCount', $decoded['summary']);
        $this->assertArrayHasKey('totalRedirectHops', $decoded['summary']);
        $this->assertEquals(1, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(2, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_table_no_redirect_chain_warning_when_none(): void
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
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        $this->assertStringNotContainsString('Redirect chains:', $lines);
    }

    public function test_format_table_single_redirect_not_counted_as_chain(): void
    {
        // A single redirect (1 hop) should not be counted as a "chain" (2+ hops)
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/final'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        // Single redirect should not trigger "Redirect chains" warning
        $this->assertStringNotContainsString('Redirect chains:', $lines);
    }

    public function test_format_json_single_redirect_has_zero_chain_count(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/final'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        // Single redirect = 0 chains, but 1 hop
        $this->assertEquals(0, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(1, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_csv_includes_redirect_chain_in_output(): void
    {
        $results = [
            [
                'url' => 'https://example.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);

        $this->formatter->format($results, $config, $output);

        // CSV should include redirects column with chain
        $dataLine = $output->lines[1];
        $this->assertStringContainsString('step1', $dataLine);
        $this->assertStringContainsString('step2', $dataLine);
    }

    public function test_format_table_verbose_shows_redirect_chain_details(): void
    {
        $results = [
            [
                'url' => 'https://example.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $output->verbose = true;
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Verbose mode should show redirect details in table
        $this->assertContains('Redirects', $output->tables[0]['headers']);

        // Check that the row contains redirect chain info
        $firstRow = $output->tables[0]['rows'][0];
        $this->assertArrayHasKey('Redirects', $firstRow);
        $this->assertStringContainsString('step1', $firstRow['Redirects']);
    }

    public function test_format_json_includes_redirect_chain_in_results(): void
    {
        $results = [
            [
                'url' => 'https://example.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertArrayHasKey('redirectChain', $decoded['results'][0]);
        $this->assertCount(2, $decoded['results'][0]['redirectChain']);
        $this->assertEquals('https://example.com/step1', $decoded['results'][0]['redirectChain'][0]);
        $this->assertEquals('https://example.com/step2', $decoded['results'][0]['redirectChain'][1]);
    }

    public function test_format_table_multiple_chains_counted_correctly(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/a', 'https://example.com/b'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/page2',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/c', 'https://example.com/d', 'https://example.com/e'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/page3',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/single'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/page4',
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
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        // 2 chains (2+ hops), 6 total hops (2 + 3 + 1 + 0)
        $this->assertEquals(2, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(6, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_json_empty_results_has_zero_chain_count(): void
    {
        $results = [];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertEquals(0, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(0, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_table_displays_long_chain_with_many_hops(): void
    {
        // Test with a very long redirect chain (5 hops)
        $results = [
            [
                'url' => 'https://example.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [
                    'https://example.com/hop1',
                    'https://example.com/hop2',
                    'https://example.com/hop3',
                    'https://example.com/hop4',
                    'https://example.com/hop5',
                ],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertEquals(1, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(5, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_table_chain_count_singular_label(): void
    {
        // Test singular "chain" vs plural "chains"
        $results = [
            [
                'url' => 'https://example.com/page1',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        $lines = implode("\n", $output->lines);
        $this->assertStringContainsString('1 chain', $lines);
    }

    public function test_format_json_redirect_chain_with_broken_link(): void
    {
        // Test redirect chain that ends in a broken link
        $results = [
            [
                'url' => 'https://example.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 404,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/step1', 'https://example.com/step2'],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        // Chain count should still be 1 even though link is broken
        $this->assertEquals(1, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(2, $decoded['summary']['totalRedirectHops']);
        // Broken link should appear in brokenLinks
        $this->assertCount(1, $decoded['brokenLinks']);
    }

    public function test_format_csv_empty_redirect_chain_shows_empty_redirects(): void
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
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);

        $this->formatter->format($results, $config, $output);

        // Data line should have empty redirects
        $dataLine = $output->lines[1];
        // Redirects column should be empty (consecutive commas or empty quoted string)
        $this->assertStringContainsString('""', $dataLine);
    }

    public function test_format_table_mixed_redirects_and_chains(): void
    {
        // Mix of no redirects, single redirects, and chains
        $results = [
            [
                'url' => 'https://example.com/direct',
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
                'url' => 'https://example.com/single-redirect',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/hop1'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
            [
                'url' => 'https://example.com/chain',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => ['https://example.com/hop1', 'https://example.com/hop2'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        // Only 1 chain (2+ hops), but 3 total hops (0 + 1 + 2)
        $this->assertEquals(1, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(3, $decoded['summary']['totalRedirectHops']);
    }

    public function test_format_table_redirect_chain_with_external_urls(): void
    {
        // Test redirect chain that includes external URLs
        $results = [
            [
                'url' => 'https://external.com/final',
                'sourcePage' => 'https://example.com',
                'status' => 200,
                'type' => 'external',
                'redirectChain' => ['https://example.com/redirect', 'https://external.com/final'],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output);

        $jsonOutput = implode("\n", $output->lines);
        $decoded = json_decode($jsonOutput, true);

        $this->assertEquals(1, $decoded['summary']['redirectChainCount']);
        $this->assertEquals(2, $decoded['summary']['totalRedirectHops']);
        $this->assertContains('https://external.com/final', $decoded['results'][0]['redirectChain']);
    }

    public function test_format_verbose_table_no_redirect_row_when_no_chain(): void
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
        ];

        $output = $this->createMockOutput();
        $output->verbose = true;
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output);

        // Verbose mode should still have Redirects header
        $this->assertContains('Redirects', $output->tables[0]['headers']);

        // But the row should not have a Redirects key since chain is empty
        $firstRow = $output->tables[0]['rows'][0];
        $this->assertArrayNotHasKey('Redirects', $firstRow);
    }

    // ===================
    // Error output tests (rate limit abort)
    // ===================

    public function test_format_table_shows_error_message(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
                'status' => 429,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output, 'Scan aborted due to rate limiting');

        // Should show error message
        $this->assertNotEmpty($output->errors);
        $this->assertStringContainsString('Scan aborted due to rate limiting', $output->errors[0]);
    }

    public function test_format_json_includes_error_key(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
                'status' => 429,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'json']);

        $this->formatter->format($results, $config, $output, 'Scan aborted due to rate limiting');

        // Parse JSON output
        $jsonOutput = json_decode($output->lines[0], true);
        $this->assertArrayHasKey('error', $jsonOutput);
        $this->assertEquals('Scan aborted due to rate limiting', $jsonOutput['error']);
    }

    public function test_format_csv_includes_error_comment(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
                'status' => 429,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $output = $this->createMockOutput();
        $config = $this->createConfig(['outputFormat' => 'csv']);

        $this->formatter->format($results, $config, $output, 'Scan aborted due to rate limiting');

        // First line should be error comment
        $this->assertStringStartsWith('# Error:', $output->lines[0]);
        $this->assertStringContainsString('Scan aborted due to rate limiting', $output->lines[0]);
    }

    public function test_format_table_no_error_when_null(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
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
        $config = $this->createConfig(['outputFormat' => 'table']);

        $this->formatter->format($results, $config, $output, null);

        // Should not have any error messages about rate limiting in errors array
        $hasRateLimitError = false;
        foreach ($output->errors as $error) {
            if (str_contains(strtolower($error), 'rate limiting')) {
                $hasRateLimitError = true;
                break;
            }
        }
        $this->assertFalse($hasRateLimitError, 'Should not have rate limiting error when error is null');
    }

    public function test_to_json_array_includes_error(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
                'status' => 429,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => false,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $config = $this->createConfig();
        $jsonArray = $this->formatter->toJsonArray($results, $config, 'Scan aborted due to rate limiting');

        $this->assertArrayHasKey('error', $jsonArray);
        $this->assertEquals('Scan aborted due to rate limiting', $jsonArray['error']);
    }

    public function test_to_json_array_no_error_key_when_null(): void
    {
        $results = [
            [
                'url' => 'https://example.com',
                'sourcePage' => 'start',
                'status' => 200,
                'type' => 'internal',
                'redirectChain' => [],
                'isOk' => true,
                'isLoop' => false,
                'hasHttpsDowngrade' => false,
                'sourceElement' => 'a',
            ],
        ];

        $config = $this->createConfig();
        $jsonArray = $this->formatter->toJsonArray($results, $config, null);

        $this->assertArrayNotHasKey('error', $jsonArray);
    }
}
