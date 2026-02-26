# Scannr

A Laravel-based website scanner that crawls websites to detect broken links using BFS (Breadth-First Search) crawling.

## Features

- **BFS Crawling**: Systematically crawls websites level by level
- **Broken Link Detection**: Identifies links returning 4xx/5xx status codes
- **Multi-Element Scanning**: Extracts and scans URLs from `<a>`, `<link>`, `<script>`, and `<img>` elements
- **Element Type Filtering**: Filter results by element type (anchors, images, scripts, stylesheets)
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
| `--filter=TYPE` | all | Filter displayed results by element type: `all`, `a`, `link`, `script`, or `img` |
| `--scan-elements=TYPES` | all | Element types to scan: `all`, or comma-separated list (e.g., `a,img`) |
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

**Show only broken images:**

```bash
php artisan site:scan https://example.com --status=broken --filter=img
```

**Show only script resources:**

```bash
php artisan site:scan https://example.com --filter=script
```

**Show only stylesheet/link resources:**

```bash
php artisan site:scan https://example.com --filter=link
```

**Scan only anchor links (skip images, scripts, stylesheets):**

```bash
php artisan site:scan https://example.com --scan-elements=a
```

**Scan only anchor links and images:**

```bash
php artisan site:scan https://example.com --scan-elements=a,img
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

+--------------------------------------------------+------------------------------+---------+--------+----------+
| URL                                              | Source                       | Element | Status | Type     |
+--------------------------------------------------+------------------------------+---------+--------+----------+
| https://example.com/about                        | https://example.com          | <a>     | 200    | internal |
| https://example.com/style.css                    | https://example.com          | <link>  | 200    | internal |
| https://example.com/app.js                       | https://example.com          | <script>| 200    | internal |
| https://example.com/missing.png                  | https://example.com/about    | <img>   | 404    | internal |
| https://external-site.com                        | https://example.com/links    | <a>     | 200    | external |
+--------------------------------------------------+------------------------------+---------+--------+----------+
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
      "sourceElement": "a",
      "status": 200,
      "type": "internal",
      "redirectChain": [],
      "isOk": true,
      "isLoop": false,
      "hasHttpsDowngrade": false
    },
    {
      "url": "https://example.com/logo.png",
      "sourcePage": "https://example.com",
      "sourceElement": "img",
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
URL,Source,Element,Status,Type,Redirects,IsOk,HttpsDowngrade
"https://example.com/about","https://example.com","a","200","internal","","true","false"
"https://example.com/style.css","https://example.com","link","200","internal","","true","false"
"https://example.com/missing.png","https://example.com/about","img","404","internal","","false","false"
```

## How It Works

1. **Initialization**: The scanner starts with the provided URL as the seed
2. **Sitemap Discovery** (optional): When `--sitemap` is used, the scanner:
   - Checks robots.txt for Sitemap directives
   - Tries common sitemap locations (sitemap.xml, sitemap_index.xml, sitemap/)
   - Parses XML sitemaps, sitemap index files, HTML sitemaps, and plain text sitemaps
   - Adds discovered URLs to the crawl queue
3. **BFS Queue**: URLs are processed in a breadth-first manner, ensuring closer pages are scanned first
4. **Internal Pages**: For internal URLs, the scanner fetches the full page and extracts URLs from:
   - `<a href="">` - Anchor links to other pages
   - `<link href="">` - Stylesheets, favicons, and other linked resources
   - `<script src="">` - JavaScript files
   - `<img src="">` - Images
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
- Use `--element` to filter results by the HTML element type that contained the URL

## Element Types

The scanner extracts and tracks URLs from the following HTML elements:

| Element | Filter Value | Description |
|---------|--------------|-------------|
| `<a href="">` | `a` | Anchor links to other pages |
| `<link href="">` | `link` | Stylesheets, favicons, preload resources |
| `<script src="">` | `script` | JavaScript files |
| `<img src="">` | `img` | Images |

### Filtering vs. Scanning

There are two ways to control which elements are processed:

- **`--scan-elements`**: Controls which element types are **checked for broken links**. Pages are still crawled to discover content, but only the specified element types have their URLs verified. This saves HTTP requests and speeds up the scan.

- **`--filter`**: Filters the **displayed results** after scanning. Useful when you've scanned everything but want to focus on a specific element type in the output.

**When to use each:**

```bash
# Fast: Only check if images are broken (skip checking scripts, stylesheets, external links)
php artisan site:scan https://example.com --scan-elements=img

# Slow but complete: Check everything, but only show images in output
php artisan site:scan https://example.com --filter=img

# Combined: Check pages and images only, show only broken images
php artisan site:scan https://example.com --scan-elements=a,img --filter=img --status=broken
```

**Note:** When using `--scan-elements=img`, pages (`<a>` links) are still crawled to discover images, but anchor links themselves are not checked for broken status - only the images found on those pages are verified.

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
