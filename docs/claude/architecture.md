# Architecture

How a scan moves through the system, and which classes own which decisions.

## The big picture

Scannr is a Laravel package with one CLI entry point (`site:scan`) that
orchestrates a BFS crawl over a single host. The crawler dispatches every
discovered URL to a checker, classifies the result, and either feeds new links
back into the queue (internal HTML pages) or stops at that node (assets,
externals). After the crawl completes, results are run through a scorer and a
formatter.

The package can also persist a scan as a queued job (`--queue`), and can run
inside a Docker-based GitHub Action.

## Service map

All public services are registered as singletons in
`src/ScannrServiceProvider.php`. Aliases (`'scanner'`, `'http-checker'`, etc.)
mirror the FQCN bindings so both work in the container.

```
CrawlerService             BFS orchestrator; owns queue, visited set, 429 abort
  ├── ScannerService       per-URL: fetch + classify + flag
  │     ├── HttpChecker         HTTP request; follows redirects up to 5; loop / HTTPS-downgrade detection
  │     ├── LinkExtractor       parses HTML/JS for <a>, <link>, <script>, <img>, forms, etc.
  │     ├── UrlNormalizer       strips tracking params, resolves relative URLs, canonicalises
  │     └── LinkFlagService     applies LinkFlag values (uses UrlNormalizer + SeverityEvaluator)
  │             └── SeverityEvaluator   maps flag combos → Severity + Confidence
  ├── SitemapService        parses XML / HTML / plain-text sitemaps and sitemap indexes
  ├── RobotsService         parses robots.txt; Disallow rules + Crawl-delay
  ├── SpaDetector           heuristic: empty DOM + no links + framework signals → SPA
  ├── BrowsershotFetcher    spatie/browsershot wrapper (Puppeteer + Chromium)
  └── ScanStatistics        result aggregation: counts, working/broken/redirects

After the crawl:
  IntegrityScorer           applies penalties → overall + category sub-scores
  ResultFormatterService    filters noise, applies user filters, renders table/JSON/CSV
  ConsoleOutput             progress bars + integrity panel (implements OutputInterface)
```

`CrawlerService` is the only service that does not get registered explicitly —
it's resolved by Laravel via constructor injection through `ScanSite`.

## Crawl lifecycle

1. **`ScanSite::handle()`** (`src/Console/Commands/ScanSite.php`)
   - parses CLI options into `ScanConfig` via `ScanConfig::fromCommandOptions`.
   - validates the URL host against `APP_URL` (warning only — non-blocking).
   - if `--queue`: creates a `ScanResult` model and dispatches `ScanSiteJob`.
   - otherwise: calls `CrawlerService::crawl()` synchronously.

2. **`CrawlerService::crawl()`**
   - seeds a BFS queue with the start URL (and any sitemap entries if
     `--sitemap`).
   - fetches `robots.txt` once unless `--no-robots`; honors `Disallow` and
     `Crawl-delay`.
   - loop: dequeue → respect rate limit (config min/max ms) → call
     `ScannerService` → if internal HTML, extract more links and enqueue
     unvisited.
   - tracks 429 count globally; aborts if `rate_limit.max_429_before_abort`
     is exceeded.
   - if `--smart-js` and `SpaDetector` flags the page, switches to
     `BrowsershotFetcher` for that URL.

3. **`ScannerService::processInternalUrl()` / `processExternalUrl()`**
   - calls `HttpChecker::checkUrl()` for status + final URL + redirect chain.
   - if internal, calls `LinkExtractor::extractLinks()` on the HTML body.
   - applies `LinkFlagService` to attach flags.
   - `SeverityEvaluator` derives Severity + Confidence from flag combinations.
   - returns a result array with: `url`, `status`, `type` (internal/external),
     `element`, `source`, `analysis` (flags + severity + confidence), and
     redirect metadata.

4. **`IntegrityScorer::calculate()`**
   - walks every result; resolves at most one `IssueType` per result via
     `resolveIssueType()` (priority-ordered chain — order matters).
   - `effectivePenalty = basePenalty × confidenceMultiplier × dampeningFactor`.
   - overall score = `100 − Σ effectivePenalty` (no floor; can go negative in
     pathological cases but the formatter clamps display).
   - per-category score = `max(0, 100 − categoryPenalty × category_penalty_multiplier)`.
   - returns `IntegrityScoreResult` with overall, five category sub-scores,
     and grade.

5. **`ResultFormatterService::format()`**
   - applies noise filtering (XML namespaces, CDN preconnect, framework error
     docs — config-driven; suppressed unless `--advanced`).
   - applies user filters (`--status`, `--filter`).
   - dispatches to `--format=table|json|csv` writers.
   - writes the CI summary file to `/tmp/scannr-ci-summary.json` for the
     GitHub Action to pick up.

6. **Exit code**
   - quality gates (`--fail-on-broken`, `--fail-on-critical`, `--min-rating`)
     are enforced inside `ScanSite::handle()` after formatting. Exit 1 if any
     gate fails, 0 otherwise.

## Queued path

`--queue` swaps step 1's synchronous crawl for `ScanSiteJob::handle()`:

- `ScanResult` row is created in `pending` state with `ScanConfig` serialized
  to the `config` JSON column.
- Job transitions `pending → running → completed | failed`.
- Final results are serialized into the `results` JSON column.
- Job timeout is `config('scannr.job_timeout')` (default 600s).
- Single attempt — no retries. A failed scan stays failed; the user re-runs.

The queue migration must be published with `--tag=scannr-migrations` before
`--queue` is usable.

## Where to start changing things

| Goal                                          | Start in                                      |
| --------------------------------------------- | --------------------------------------------- |
| Change how links are discovered in HTML/JS    | `src/Services/LinkExtractor.php`              |
| Add a flag (developer_leftover, malformed, …) | `src/Services/LinkFlagService.php` + enum     |
| Change what makes a result CRITICAL           | `src/Services/SeverityEvaluator.php`          |
| Change the scoring math or category mapping   | `src/Services/IntegrityScorer.php` + config   |
| Filter more noise from output                 | `config/scannr.php` `noise_urls.*`            |
| Change how SPAs are detected                  | `src/Services/SpaDetector.php`                |
| Change redirect handling                      | `src/Services/HttpChecker.php`                |
| Add a CLI option                              | `ScanSite::$signature` + `ScanConfig`         |
| Add a GitHub Action input                     | `action.yml` + `entrypoint.sh` + ScanConfig   |

## What lives outside the package

- Migrations are published into the host app via `--tag=scannr-migrations` —
  they live in `database/migrations/` here and copy into the host's
  `database/migrations/` at publish time.
- Config is published via `--tag=scannr-config` from `config/scannr.php`.
- `Puppeteer` (Node.js) and Chromium are runtime dependencies for `--js` /
  `--smart-js`. The package does **not** install them; users must.
