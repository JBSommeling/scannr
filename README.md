# Scannr

A Laravel-based website scanner that crawls websites to detect broken links using BFS (Breadth-First Search) crawling.

## Features

- **BFS Crawling**: Systematically crawls websites level by level
- **Broken Link Detection**: Identifies links returning 4xx/5xx status codes
- **Redirect Chain Tracking**: Follows and reports redirect chains, including loop detection
- **HTTPS Downgrade Detection**: Warns when redirects downgrade from HTTPS to HTTP
- **Sitemap Integration**: Discover URLs from XML, HTML, or plain text sitemaps
- **Sitemap Index Support**: Recursively parses sitemap index files
- **robots.txt Support**: Automatically discovers sitemaps from robots.txt
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
| `--sitemap` | false | Use sitemap.xml to discover URLs before crawling |

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

**Scan using sitemap for URL discovery:**

```bash
php artisan site:scan https://example.com --sitemap
```

**Combine sitemap discovery with custom settings:**

```bash
php artisan site:scan https://example.com --sitemap --depth=2 --max=500 --format=json
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

  ⚠ Redirect chains: 5 chains, 8 total hops
  ⚠ HTTPS downgrades: 1

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
    "timeouts": 1,
    "redirectChainCount": 5,
    "totalRedirectHops": 8,
    "httpsDowngrades": 1
  },
  "results": [
    {
      "url": "https://example.com/about",
      "sourcePage": "https://example.com",
      "status": 200,
      "type": "internal",
      "redirectChain": [],
      "isOk": true,
      "isLoop": false,
      "hasHttpsDowngrade": false
    }
  ]
}
```

### CSV Format

Outputs CSV data suitable for spreadsheet applications:

```csv
URL,Source,Status,Type,Redirects,IsOk,HttpsDowngrade
"https://example.com/about","https://example.com","200","internal","","true","false"
"https://example.com/missing","https://example.com/about","404","internal","","false","false"
```

## How It Works

1. **Initialization**: The scanner starts with the provided URL as the seed
2. **Sitemap Discovery** (optional): When `--sitemap` is used, the scanner:
   - Checks robots.txt for Sitemap directives
   - Tries common sitemap locations (sitemap.xml, sitemap_index.xml, sitemap/)
   - Parses XML sitemaps, sitemap index files, HTML sitemaps, and plain text sitemaps
   - Adds discovered URLs to the crawl queue
3. **BFS Queue**: URLs are processed in a breadth-first manner, ensuring closer pages are scanned first
4. **Internal Pages**: For internal URLs, the scanner fetches the full page and extracts all links
5. **External Links**: External URLs are checked with HEAD requests for efficiency
6. **Redirect Handling**: Redirects are followed up to 5 hops, with loop detection and HTTPS downgrade warnings
7. **Deduplication**: Each URL is only scanned once, regardless of how many pages link to it

## Notes

- The scanner respects the `--depth` limit for crawling, but will still check external links found at any depth
- SSL certificate verification is disabled by default to handle sites with certificate issues
- Timeout errors are tracked separately from HTTP error responses
- Use verbose mode (`-v`) to see redirect chains in table output and list HTTPS downgraded URLs
- The `--sitemap` option discovers URLs from sitemaps before crawling, treating them as entry points (depth=0)
- Sitemap discovery supports XML sitemaps, sitemap index files, HTML sitemaps, and plain text URL lists
- HTTPS to HTTP redirect downgrades are flagged as potential security concerns

## Sitemap Support

The scanner can discover URLs from various sitemap formats:

### Supported Formats

- **XML Sitemaps**: Standard sitemap.xml files following the sitemap protocol
- **Sitemap Index**: Index files containing references to multiple sitemaps (recursively parsed up to 3 levels)
- **HTML Sitemaps**: HTML pages with links (typically at /sitemap/)
- **Plain Text**: Simple text files with one URL per line

### Discovery Order

When using `--sitemap`, the scanner checks for sitemaps in this order:

1. Sitemaps declared in robots.txt (`Sitemap:` directive)
2. `/sitemap.xml`
3. `/sitemap_index.xml`
4. `/sitemap/`

The first working sitemap found is used, and discovered URLs are added to the crawl queue with depth=0, so the crawler will also find and scan any links on those pages.

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
