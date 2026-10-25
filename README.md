[![Latest Stable Version](https://img.shields.io/packagist/v/jbsommeling/scannr.svg)](https://packagist.org/packages/jbsommeling/scannr)
[![Total Downloads](https://img.shields.io/packagist/dt/jbsommeling/scannr.svg)](https://packagist.org/packages/jbsommeling/scannr)
[![Tests](https://img.shields.io/github/actions/workflow/status/JBSommeling/scannr/tests.yml?label=tests)](https://github.com/JBSommeling/scannr/actions)
[![License](https://img.shields.io/packagist/l/jbsommeling/scannr.svg)](https://packagist.org/packages/jbsommeling/scannr)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/jbsommeling/scannr/php)](https://packagist.org/packages/jbsommeling/scannr)

# Scannr

Scannr crawls your website and reports broken links, redirect chains, HTTPS downgrades, and
other link-health problems, then scores the site from 0 to 100. Run it from Artisan as a dev
dependency in a Laravel project, or as a Docker-based GitHub Action that can fail a build when
the score drops.

## What it checks

Scannr crawls breadth-first from a starting URL, optionally seeded from your sitemap (XML,
HTML, plain text, or sitemap index). On each page it collects URLs from `<a>`, `<link>`,
`<script>`, `<img>`, media elements, download triggers, and inline JS handlers, then requests
each one and records:

- broken links (4xx and 5xx responses) and timeouts;
- redirect chains, including loops;
- redirects that downgrade from HTTPS to HTTP;
- flags on every link describing how it was found, platform traits, technical anomalies, and
  the validation outcome.

Sites that render in the browser (React, Vue, Angular) can be scanned with a headless browser,
either always (`--js`) or only when Scannr detects an SPA (`--smart-js`).

Scannr respects `robots.txt` `Disallow` rules and `Crawl-delay`, backs off exponentially on
HTTP 429, and hides noise such as XML namespaces, CDN preconnect hints, and JS framework error
docs unless you pass `--advanced`. Results come out as a table, JSON, or CSV.

## Installation

```bash
composer require jbsommeling/scannr
```

The service provider is auto-discovered. Publish the config if you want to change defaults
(see [Configuration](#configuration)):

```bash
php artisan vendor:publish --tag=scannr-config
```

`--queue` stores results in the database, so it needs the migration:

```bash
php artisan vendor:publish --tag=scannr-migrations
php artisan migrate
```

`--js` and `--smart-js` need Node.js and Puppeteer:

```bash
npm install puppeteer
```

## Running a scan

```bash
php artisan site:scan https://example.com
```

Common variations:

```bash
# Show only broken links
php artisan site:scan https://example.com --status=broken

# Deeper scan, seeded from the sitemap
php artisan site:scan https://example.com --depth=5 --max=1000 --sitemap

# Scan a React SPA and print JSON
php artisan site:scan https://my-spa.com --js --format=json

# Check only anchors and images, show only failures
php artisan site:scan https://example.com --scan-elements=a,img --status=broken

# Wait a random 200–500 ms between requests
php artisan site:scan https://example.com --delay-min=200 --delay-max=500

# Exit 1 on critical issues or a rating below Good
php artisan site:scan https://example.com --fail-on-critical --min-rating=good
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--depth=N` | 3 | Maximum crawl depth from the starting URL |
| `--max=N` | 300 | Maximum number of URLs to scan |
| `--timeout=N` | 5 | Request timeout in seconds |
| `--format=FORMAT` | table | Output format: `table`, `json`, or `csv` |
| `--status=FILTER` | all | Show `all`, `ok`, or `broken` results |
| `--filter=TYPE` | all | Show only one element type: `all`, `a`, `link`, `script`, `img`, `media`, `form` |
| `--scan-elements=TYPES` | all | Element types to scan: `all`, or comma-separated (e.g. `a,img`) |
| `--sitemap` | off | Discover URLs from the sitemap before crawling |
| `--js` | off | Render every page with a headless browser |
| `--smart-js` | off | Render with a headless browser only when an SPA is detected |
| `--no-robots` | off | Ignore `robots.txt` rules |
| `--advanced` | off | Show noise URLs (XML namespaces, CDN hints, etc.) |
| `--strip-params=PARAMS` | — | Extra tracking parameters to strip (comma-separated) |
| `--delay-min=N` | config | Minimum delay between requests, in ms |
| `--delay-max=N` | config | Maximum delay between requests, in ms |
| `--queue` | off | Run the scan as a background job |
| `--fail-on-broken` | off | Exit 1 if any link is broken |
| `--fail-on-critical` | off | Exit 1 if any critical issue is found |
| `--min-rating=GRADE` | none | Exit 1 below this rating: `excellent`, `good`, `needs_attention`, or `none` |

### Integrity score and quality gates

Every scan ends with an integrity score from 0 to 100, built from five components: link
integrity, security hygiene, technical hygiene, redirect health, and link verifiability. The
score maps to a grade:

| Grade | Score |
|-------|-------|
| Excellent | 90 and above |
| Good | 75 and above |
| Needs Attention | 50 and above |
| Critical | below 50 |

Penalty weights and grade thresholds live in `config/scannr.php`.

A scan only fails, with exit code 1, when you ask it to: `--fail-on-broken`,
`--fail-on-critical`, or `--min-rating`. Without them the exit code is 0 however bad the
results are. The same holds for the GitHub Action.

### Example output

<details>
<summary>Table (default)</summary>

```
Site Scan: https://www.example.com
========================================

  Robots.txt Crawl-delay: 1s (using 1000ms-1000ms delay)
  Robots.txt: respecting 4 Disallow/Allow rule(s)
 11/30 [▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░]  36% 30/30 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


  🟢 Site Integrity Score: 100.0 / 100  —  Excellent

    Link Integrity:        100.0 / 100
    Security Hygiene:      100.0 / 100
    Technical Hygiene:     100.0 / 100
    Redirect Health:       100.0 / 100
    Link Verifiability:    100.0 / 100

  Critical Issues:       0
  Broken Links:          0
  Warnings:              0
  Manual Verification:   0

Summary:
  Total scanned:     11
  Pages scanned:     2
  Internal links:    1
  Assets scanned:    9
  External links:    1
  Working (2xx):     11
  Redirects:         0
  Broken:            0
  Timeouts:          0

+----------------------------------------------+--------------------------+----------+--------+----------+
| URL                                          | Source                   | Element  | Status | Type     |
+----------------------------------------------+--------------------------+----------+--------+----------+
| https://www.example.com                      | start                    | <a>      | 200    | internal |
| https://example.com/favicon.svg              | https://www.example.com  | <link>   | 200    | internal |
| https://example.com/favicon.ico              | https://www.example.com  | <link>   | 200    | internal |
| https://example.com/assets/app-Dk29a1xC.css  | https://www.example.com  | <link>   | 200    | internal |
| https://example.com/assets/app-Bx7L3f2q.js   | https://www.example.com  | <script> | 200    | internal |
| https://fonts.googleapis.com/css2?family=... | https://www.example.com  | <link>   | 200    | external |
+----------------------------------------------+--------------------------+----------+--------+----------+
```

</details>

<details>
<summary>JSON (<code>--format=json</code>)</summary>

```json
{
    "summary": {
        "totalScanned": 8,
        "ok": 8,
        "redirects": 0,
        "broken": 0,
        "timeouts": 0,
        "redirectChainCount": 0,
        "totalRedirectHops": 0,
        "httpsDowngrades": 0,
        "criticalCount": 0,
        "warningCount": 0,
        "lowConfidenceCount": 0,
        "pagesScanned": 2,
        "internalLinks": 1,
        "assetsScanned": 6,
        "externalLinks": 1
    },
    "integrityScore": {
        "overallScore": 100,
        "grade": "Excellent",
        "components": {
            "linkIntegrity": 100,
            "securityHygiene": 100,
            "technicalHygiene": 100,
            "redirectHealth": 100,
            "linkVerifiability": 100
        }
    },
    "links": [
        {
            "url": "https://www.example.com",
            "sourcePage": "start",
            "status": "200",
            "type": "internal",
            "sourceElement": "a",
            "analysis": {
                "flags": [],
                "severity": "info",
                "confidence": "high",
                "verification": "none"
            },
            "redirect": {
                "chain": [],
                "isLoop": false,
                "hasHttpsDowngrade": false
            },
            "network": {
                "retryAfter": null
            }
        }
    ],
    "brokenLinks": []
}
```

</details>

## GitHub Action

```yaml
name: Scan for broken links
on:
  push:
    branches: [main]
  schedule:
    - cron: '0 6 * * 1'  # Mondays at 06:00 UTC

jobs:
  scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: JBSommeling/scannr@v1
        with:
          url: https://example.com
          fail-on-critical: true
          min-rating: good
```

Without `fail-on-broken`, `fail-on-critical`, or `min-rating`, the step reports results but
never fails.

### Inputs

Every CLI option except `--queue` is available as an input of the same name. Only `url` is
required.

```yaml
- uses: JBSommeling/scannr@v1
  with:
    url: https://example.com       # Required
    depth: 5                        # Max crawl depth (default: 3)
    max: 500                        # Max URLs to scan (default: 300)
    timeout: 10                     # Request timeout in seconds (default: 5)
    format: table                   # table, json, or csv (default: table)
    status: broken                  # all, ok, or broken (default: all)
    filter: all                     # Element type to show (default: all)
    scan-elements: a,img            # Element types to scan (default: all)
    sitemap: true                   # Seed from sitemap (default: false)
    js: true                        # Always render with a headless browser (default: false)
    smart-js: true                  # Render only when an SPA is detected (default: false)
    no-robots: false                # Ignore robots.txt (default: false)
    advanced: false                 # Show noise URLs (default: false)
    strip-params: ref,tracker       # Extra tracking params to strip
    delay-min: 200                  # Min delay between requests, in ms
    delay-max: 500                  # Max delay between requests, in ms
    fail-on-broken: true            # Fail on any broken link (default: false)
    fail-on-critical: true          # Fail on critical issues (default: false)
    min-rating: good                # excellent, good, or needs_attention (default: none)
```

### Outputs

| Output | Description |
|--------|-------------|
| `exit-code` | `0` on success, `1` when a quality gate failed |
| `score` | Integrity score, 0–100 |
| `grade` | `Excellent`, `Good`, `Needs Attention`, or `Critical` |
| `broken-count` | Number of broken links |
| `critical-count` | Number of critical issues |

### Examples

Scan an SPA and show only broken links:

```yaml
- uses: JBSommeling/scannr@v1
  with:
    url: https://my-react-app.com
    js: true
    status: broken
```

Report without failing, then act on the counts in a later step:

```yaml
- uses: JBSommeling/scannr@v1
  id: scan
  with:
    url: https://example.com
    format: json

- name: Report broken links
  if: steps.scan.outputs.broken-count != '0'
  run: echo "Found ${{ steps.scan.outputs.broken-count }} broken links (grade ${{ steps.scan.outputs.grade }})"
```

Weekly deep audit seeded from the sitemap:

```yaml
- uses: JBSommeling/scannr@v1
  with:
    url: https://example.com
    depth: 5
    max: 1000
    sitemap: true
    smart-js: true
```

Strict gate that requires an Excellent rating:

```yaml
- uses: JBSommeling/scannr@v1
  with:
    url: https://example.com
    fail-on-critical: true
    min-rating: excellent
```

## Scan only sites you own

Scannr is meant for websites you own or have explicit permission to test. You are responsible
for staying within the site's terms of service, applicable law, and its `robots.txt`. Don't
use it to crawl sites without permission, overload servers, get around rate limits, or reach
protected or private content. The authors are not responsible for misuse.

Two checks back this up:

**Artisan** compares the scan URL's domain with `APP_URL` and warns on a mismatch. It does
not block the scan, since scanning a staging or production domain from a local checkout is
normal.

```
⚠ The scan URL domain (other-site.com) does not match your APP_URL (https://myapp.com).
  Scannr is intended for scanning your own websites.
```

**The GitHub Action** reads an optional `.scannr.yml` in the repository root. If the file
exists, the action blocks any URL whose domain isn't listed:

```yaml
# .scannr.yml
allowed_domains:
  - example.com
  - staging.example.com
  - www.example.com
```

Without `.scannr.yml`, the action trusts the `url` input, because you control the workflow
file.

## Configuration

`php artisan vendor:publish --tag=scannr-config` creates `config/scannr.php`, where you can
change:

- tracking parameters stripped during URL normalization (`utm_*`, `fbclid`, etc.);
- the User-Agent sent with requests;
- request delays and 429 backoff;
- hard caps on depth and URL count;
- paths to the Node, npm, and Chrome binaries used for JS rendering;
- noise filtering: namespace domains, preconnect hints, framework error patterns;
- scoring: penalty weights, confidence multipliers, grade thresholds;
- external platforms with known bot protection (LinkedIn, GitHub, etc.).

## Testing

```bash
composer test
```

## License

MIT. See the [license](https://opensource.org/licenses/MIT).
