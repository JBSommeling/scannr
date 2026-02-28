# Scannr

A Laravel-based website scanner that crawls websites to detect broken links using BFS (Breadth-First Search) crawling.

## Features

- **BFS Crawling**: Systematically crawls websites level by level
- **Broken Link Detection**: Identifies links returning 4xx/5xx status codes
- **Multi-Element Scanning**: Extracts and scans URLs from `<a>`, `<link>`, `<script>`, `<img>`, media elements (`<video>`, `<audio>`, `<object>`, `<embed>`), download triggers (`<a download>`, `data-href`, `data-url`, etc.), and inline JS handlers (`onclick`)
- **Element Type Filtering**: Filter results by element type (anchors, images, scripts, stylesheets)
- **URL Normalization**: Normalizes URLs by removing fragments, trailing slashes, and tracking parameters
- **Tracking Parameter Stripping**: Automatically removes common tracking params (utm_*, fbclid, gclid, ref, source)
- **Redirect Chain Tracking**: Follows and reports redirect chains, including loop detection
- **HTTPS Downgrade Detection**: Warns when redirects downgrade from HTTPS to HTTP
- **JavaScript Rendering**: Scan SPAs (React, Vue, Angular) with headless browser support via `--js` flag
- **Sitemap Integration**: Discover URLs from XML, HTML, or plain text sitemaps
- **Sitemap Index Support**: Recursively parses sitemap index files
- **robots.txt Support**: Automatically discovers sitemaps from robots.txt
- **robots.txt Compliance**: Respects `Disallow` rules and `Crawl-delay` directives from robots.txt (User-agent: *)
- **Internal & External Links**: Scans both internal pages and external links
- **Multiple Output Formats**: Table, JSON, or CSV output
- **Rate Limiting**: Random delay (300-500ms) between requests to avoid overwhelming servers
- **Rate Limit Backoff**: Automatic exponential backoff on HTTP 429 responses with configurable abort threshold
- **Hard Limits**: Configurable maximum caps for depth and URLs to prevent excessive resource usage
- **Configurable**: Adjustable depth, max URLs, timeout, and tracking parameters

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
| `--js` | false | Enable JavaScript rendering for SPA/React sites (requires Node.js + Puppeteer) |
| `--no-robots` | false | Ignore robots.txt rules (Disallow/Crawl-delay) |
| `--strip-params=PARAMS` | - | Additional tracking parameters to strip (comma-separated, e.g., `ref,tracker_*`) |

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

**Scan a React/Vue/Angular SPA with JavaScript rendering:**

```bash
php artisan site:scan https://example.com --js
```

**Combine JS rendering with sitemap and broken image detection:**

```bash
php artisan site:scan https://example.com --js --sitemap --scan-elements=a,img --status=broken
```

**Ignore robots.txt rules (crawl everything including disallowed paths):**

```bash
php artisan site:scan https://example.com --no-robots
```

**Strip additional tracking parameters:**

```bash
php artisan site:scan https://example.com --strip-params=tracker_*,campaign_id
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
2. **Robots.txt Compliance**: The scanner fetches and parses robots.txt:
   - Reads `User-agent: *` (and `ScannrBot`-specific) rules
   - Applies `Disallow` rules to skip blocked paths during crawling
   - Applies `Allow` rules with longest-match-wins precedence
   - Respects `Crawl-delay` by adjusting the delay between requests
   - Extracts `Sitemap:` directives for sitemap discovery
   - Use `--no-robots` to bypass these rules
3. **Sitemap Discovery** (optional): When `--sitemap` is used, the scanner:
   - Checks robots.txt for Sitemap directives
   - Tries common sitemap locations (sitemap.xml, sitemap_index.xml, sitemap/)
   - Parses XML sitemaps, sitemap index files, HTML sitemaps, and plain text sitemaps
   - Adds discovered URLs to the crawl queue
4. **BFS Queue**: URLs are processed in a breadth-first manner, ensuring closer pages are scanned first
5. **Internal Pages**: For internal URLs, the scanner fetches the full page and extracts URLs from:
   - `<a href="">` - Anchor links to other pages
   - `<link href="">` - Stylesheets, favicons, and other linked resources
   - `<script src="">` - JavaScript files
   - `<img src="">` - Images
   - `<img srcset="">`, `<img data-src="">`, `<picture source>` - Responsive and lazy-loaded images
   - `<video>`, `<audio>`, `<source>`, `<object>`, `<embed>` - Media files and embedded documents
   - `<a download>`, `[data-href]`, `[data-url]`, `[data-download]`, `[data-file]` - Download links (including JS-triggered)
   - `[onclick]` - URLs in inline JS handlers (`location.href`, `window.open()`, `download()`)
   - Inline `<script>` content (with `--js`) - Download URLs in JS data blobs, JSON config, React/Next.js/Nuxt data
   - External JS bundles (with `--js`) - Download URLs compiled into React/Vue/Svelte bundles (same-domain only)
6. **JavaScript Rendering** (optional): When `--js` is used, internal pages are rendered with a headless browser (Puppeteer) before extracting links, enabling detection of content injected by JavaScript frameworks (React, Vue, Angular, etc.)
7. **External Links**: External URLs are checked with HEAD requests for efficiency
8. **Redirect Handling**: Redirects are followed up to 5 hops, with loop detection and HTTPS downgrade warnings
9. **Deduplication**: Each URL is only scanned once, regardless of how many pages link to it

## Notes

- The scanner respects the `--depth` limit for crawling, but will still check external links found at any depth
- SSL certificate verification is disabled by default to handle sites with certificate issues
- Timeout errors are tracked separately from HTTP error responses
- A random delay (300-500ms by default) is applied between requests to avoid overwhelming servers
- Robots.txt `Disallow` rules are respected by default; use `--no-robots` to bypass
- When robots.txt specifies a `Crawl-delay`, the scanner uses the larger of the configured delay and the robots.txt delay
- Hard limits are enforced for depth (max 10) and URLs (max 2000) regardless of command line options
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
| `<video>`, `<audio>`, `<source>`, `<object>`, `<embed>` | `media` | Media files, embedded documents |
| `<a download>`, `[data-href]`, `[data-url]`, `[data-download]`, `[data-file]` | `media` | Download links, including JS-triggered downloads |
| `[onclick]` with `location.href`, `window.open()`, `download()` | `media` | Download URLs in inline JavaScript handlers |
| Inline `<script>` content (requires `--js`) | `media` | Download URLs in JS data blobs, JSON config, React/Next.js/Nuxt data |
| External JS bundles (requires `--js`) | `media` | Download URLs compiled into React/Vue/Svelte JS bundles (internal only) |

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

## Robots.txt Compliance

By default, the scanner respects robots.txt rules to be a well-behaved crawler. This is enabled automatically — no flags needed.

### What Is Respected

| Directive | Behavior |
|-----------|----------|
| `User-agent: *` | Rules under this block apply to the scanner |
| `User-agent: ScannrBot` | If present, these rules take priority over `*` |
| `Disallow: /path` | URLs matching this path prefix are skipped during crawling |
| `Allow: /path` | Overrides a `Disallow` when more specific (longest-match-wins) |
| `Crawl-delay: N` | Delay between requests is set to at least N seconds |
| `Sitemap: URL` | Used for sitemap discovery (with `--sitemap` flag) |

### Pattern Matching

The scanner supports the de facto robots.txt pattern syntax:

- **Prefix matching**: `Disallow: /admin` blocks `/admin`, `/admin/page`, `/admin/settings`, etc.
- **Wildcards**: `Disallow: /foo/*/bar` blocks `/foo/anything/bar`
- **End anchor**: `Disallow: /*.pdf$` blocks `/document.pdf` but not `/document.pdf/view`

### Examples

```
# robots.txt
User-agent: *
Disallow: /admin/
Disallow: /private/
Allow: /admin/public/
Crawl-delay: 2

Sitemap: https://example.com/sitemap.xml
```

With this robots.txt:
- `/admin/settings` → **skipped** (matches `Disallow: /admin/`)
- `/admin/public/page` → **crawled** (matches `Allow: /admin/public/`, which is more specific)
- `/private/data` → **skipped** (matches `Disallow: /private/`)
- `/about` → **crawled** (no matching rule)
- Delay between requests → **at least 2 seconds**

### Disabling Robots.txt

To ignore robots.txt rules and crawl everything (including disallowed paths):

```bash
php artisan site:scan https://example.com --no-robots
```

## JavaScript Rendering

Modern websites built with JavaScript frameworks (React, Vue, Angular, etc.) render content client-side. The default scanner only sees the initial HTML returned by the server, which for SPAs is typically just a shell with `<div id="root"></div>`. The `--js` flag enables headless browser rendering so the scanner can see the fully rendered DOM.

### Requirements

- **Node.js** (v16+)
- **Puppeteer**: Install with `npm install puppeteer`

### Usage

```bash
# Scan a React/Vue SPA
php artisan site:scan https://my-react-app.com --js

# Combine with other options
php artisan site:scan https://my-react-app.com --js --sitemap --scan-elements=a,img --format=json
```

### How It Works

When `--js` is enabled:

1. Internal pages are still fetched via HTTP (Guzzle) to check status codes and follow redirects
2. For pages that return 200, a headless Chrome instance renders the page with full JavaScript execution
3. Links and images are extracted from the **rendered DOM** instead of the raw HTML
4. If the headless browser fails for any page, the scanner falls back to the raw HTML response

This means the scanner can detect broken images, links, and resources that only exist after JavaScript execution — such as images loaded by React components or links generated by client-side routing.

### Configuration

Custom binary paths can be configured in `config/scanner.php`:

```php
'js_rendering' => [
    // Path to node binary (null = auto-detect)
    'node_binary' => null,

    // Path to npm binary (null = auto-detect)
    'npm_binary' => null,

    // Path to Chrome/Chromium binary (null = use Puppeteer's bundled Chrome)
    'chrome_path' => null,

    // Timeout for browser rendering in seconds
    'timeout' => 30,
],
```

### Performance Note

JavaScript rendering is significantly slower than static HTML parsing because each page requires launching a headless browser. For large sites, consider combining `--js` with `--scan-elements` to limit which element types are checked:

```bash
# Only check pages and images (faster than checking everything)
php artisan site:scan https://example.com --js --scan-elements=a,img
```

## URL Normalization

The scanner normalizes all URLs to ensure consistent deduplication and cleaner results:

### Normalization Rules

- **Fragment Removal**: URL fragments (`#anchor`) are stripped
- **Trailing Slash Removal**: Trailing slashes are removed for consistency
- **Absolute URLs**: Relative URLs are converted to absolute URLs
- **Tracking Parameter Stripping**: Common tracking parameters are removed (case-insensitive)

### Default Tracking Parameters

The following tracking parameters are stripped by default:

| Parameter | Description |
|-----------|-------------|
| `utm_*` | All UTM parameters (utm_source, utm_medium, utm_campaign, etc.) |
| `fbclid` | Facebook click identifier |
| `gclid` | Google Ads click identifier |
| `ref` | Referral tracking |
| `source` | Source tracking |

### Custom Tracking Parameters

You can add additional tracking parameters to strip using the `--strip-params` option:

```bash
# Strip additional tracking parameters
php artisan site:scan https://example.com --strip-params=tracker_*,campaign_id,affiliate

# Use prefix wildcard to match multiple parameters
php artisan site:scan https://example.com --strip-params=tracking_*,promo_*
```

### Configuration

Default tracking parameters can be configured in `config/scanner.php`:

```php
return [
    'tracking_params' => [
        'utm_*',
        'fbclid',
        'gclid',
        'ref',
        'source',
        // Add your custom tracking parameters here
    ],
];
```

## Rate Limiting

To avoid overwhelming target servers, the scanner applies a random delay between each HTTP request in the main crawl loop. This helps prevent being blocked and is considerate to server resources.

When a `Crawl-delay` directive is found in robots.txt, the scanner will use whichever is larger: the configured delay or the robots.txt delay. This ensures the crawler never exceeds the rate requested by the server.

### Default Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `request_delay_min` | 300ms | Minimum delay between requests |
| `request_delay_max` | 500ms | Maximum delay between requests |

The actual delay is randomly selected between the min and max values for each request.

### Configuration

Rate limiting can be configured in `config/scanner.php`:

```php
return [
    // Rate limiting (in milliseconds)
    'request_delay_min' => 300,
    'request_delay_max' => 500,
];
```

### Rate Limit Backoff (HTTP 429)

When the scanner receives HTTP 429 (Too Many Requests) responses, it automatically implements exponential backoff to handle rate limiting gracefully.

#### How It Works

1. On receiving a 429 response, the scanner waits before retrying the request
2. Each subsequent 429 increases the wait time (exponential backoff)
3. If the server sends a `Retry-After` header (in seconds), that value is used instead
4. After a configurable number of total 429 responses, the scan is aborted

#### Default Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `backoff_delays` | [2000, 5000, 10000] | Backoff delays in ms for each retry attempt |
| `respect_retry_after` | true | Use server's Retry-After header when present |
| `max_429_before_abort` | 5 | Abort scan after this many total 429 responses |

#### Configuration

Rate limit backoff can be configured in `config/scanner.php`:

```php
return [
    'rate_limit' => [
        // Backoff delays in milliseconds for each retry attempt
        // First 429: wait 2s, second: wait 5s, third: wait 10s
        'backoff_delays' => [2000, 5000, 10000],

        // Whether to respect the Retry-After header from the server
        'respect_retry_after' => true,

        // Maximum total 429 responses before aborting the scan
        // Set to 0 to disable abort (will always retry with backoff)
        'max_429_before_abort' => 5,
    ],
];
```

#### Abort Behavior

When the scan is aborted due to rate limiting:

- **Table output**: Shows error message at the top: `⚠ Error: Scan aborted due to rate limiting`
- **JSON output**: Includes `"error": "Scan aborted due to rate limiting"` in the response
- **CSV output**: Includes comment line at the top: `# Error: Scan aborted due to rate limiting`
- **Exit code**: Command returns failure exit code (1)
- **Queued jobs**: Job status is set to `aborted` with error message stored

## Hard Limits

The scanner enforces hard limits to prevent excessive resource usage, regardless of what values are passed via command line options. If a user specifies values exceeding these limits, a warning is displayed and the values are automatically capped.

### Default Hard Limits

| Setting | Default | Description |
|---------|---------|-------------|
| `hard_max_depth` | 10 | Maximum crawl depth allowed |
| `hard_max_urls` | 2000 | Maximum number of URLs to scan |
| `timeout` | 30s | Maximum request timeout |

### Example Warning

When exceeding limits, the scanner will display:

```
Depth 15 exceeds hard limit, capping to 10
Max URLs 5000 exceeds hard limit, capping to 2000
```

### Configuration

Hard limits can be configured in `config/scanner.php`:

```php
return [
    // Request timeout (seconds)
    'timeout' => 30,

    // Hard limits
    'hard_max_depth' => 10,
    'hard_max_urls' => 2000,
];
```

