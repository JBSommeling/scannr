# Scannr

A Laravel-based website scanner that crawls websites to detect broken links using BFS (Breadth-First Search) crawling.

## Features

- **BFS Crawling**: Systematically crawls websites level by level
- **Broken Link Detection**: Identifies links returning 4xx/5xx status codes
- **Redirect Chain Tracking**: Follows and reports redirect chains, including loop detection
- **Internal & External Links**: Scans both internal pages and external links
- **Multiple Output Formats**: Table, JSON, or CSV output
- **Configurable**: Adjustable depth, max URLs, and timeout settings

## Usage

### Basic Usage

Scan a website with default settings:

```bash
php artisan site:scan https://example.com
```

### Command Signature

```
php artisan site:scan {url} [options]
```

### Arguments

| Argument | Description |
|----------|-------------|
| `url` | The URL to scan (required) |

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--depth=N` | 3 | Maximum crawl depth from the starting URL |
| `--max=N` | 300 | Maximum number of URLs to scan |
| `--timeout=N` | 5 | Request timeout in seconds |
| `--format=FORMAT` | table | Output format: `table`, `json`, or `csv` |
| `--status=FILTER` | all | Filter results: `all`, `ok`, or `broken` |

### Examples

**Scan with custom depth and max URLs:**

```bash
php artisan site:scan https://example.com --depth=5 --max=500
```

**Output results as JSON:**

```bash
php artisan site:scan https://example.com --format=json
```

**Show only broken links:**

```bash
php artisan site:scan https://example.com --status=broken
```

**Export to CSV:**

```bash
php artisan site:scan https://example.com --format=csv > report.csv
```

**Quick scan with short timeout:**

```bash
php artisan site:scan https://example.com --depth=2 --max=50 --timeout=3
```

**Verbose output with redirect chains:**

```bash
php artisan site:scan https://example.com -v
```

## Output

### Table Format (Default)

Displays a summary followed by a table of all scanned links:

```
Site Scan: https://example.com
========================================

Summary:
  Total links:    150
  Working (2xx):  142
  Redirects:      5
  Broken:         2
  Timeouts:       1

+--------------------------------------------------+------------------------------+--------+----------+
| URL                                              | Source                       | Status | Type     |
+--------------------------------------------------+------------------------------+--------+----------+
| https://example.com/about                        | https://example.com          | 200    | internal |
| https://example.com/missing                      | https://example.com/about    | 404    | internal |
| https://external-site.com                        | https://example.com/links    | 200    | external |
+--------------------------------------------------+------------------------------+--------+----------+
```

### JSON Format

Returns structured JSON with summary and detailed results:

```json
{
  "summary": {
    "total": 150,
    "ok": 142,
    "redirects": 5,
    "broken": 2,
    "timeouts": 1
  },
  "results": [
    {
      "url": "https://example.com/about",
      "sourcePage": "https://example.com",
      "status": 200,
      "type": "internal",
      "redirectChain": [],
      "isOk": true,
      "isLoop": false
    }
  ]
}
```

### CSV Format

Outputs CSV data suitable for spreadsheet applications:

```csv
URL,Source,Status,Type,Redirects,IsOk
"https://example.com/about","https://example.com","200","internal","","true"
"https://example.com/missing","https://example.com/about","404","internal","","false"
```

## How It Works

1. **Initialization**: The scanner starts with the provided URL as the seed
2. **BFS Queue**: URLs are processed in a breadth-first manner, ensuring closer pages are scanned first
3. **Internal Pages**: For internal URLs, the scanner fetches the full page and extracts all links
4. **External Links**: External URLs are checked with HEAD requests for efficiency
5. **Redirect Handling**: Redirects are followed up to 5 hops, with loop detection
6. **Deduplication**: Each URL is only scanned once, regardless of how many pages link to it

## Notes

- The scanner respects the `--depth` limit for crawling, but will still check external links found at any depth
- SSL certificate verification is disabled by default to handle sites with certificate issues
- Timeout errors are tracked separately from HTTP error responses
- Use verbose mode (`-v`) to see redirect chains in table output

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
