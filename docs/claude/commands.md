# Commands

There is one artisan command: `site:scan`. Defined in
`src/Console/Commands/ScanSite.php`.

## Signature

```
php artisan site:scan <url>
    [--depth=3] [--max=300] [--timeout=5]
    [--format=table|json|csv]
    [--status=all|ok|broken]
    [--filter=all|a|link|script|img|media|form]
    [--scan-elements=all|a,img,...]
    [--strip-params=ref,tracker_*]
    [--delay-min=N] [--delay-max=N]
    [--sitemap] [--no-robots] [--advanced]
    [--js] [--smart-js]
    [--queue]
    [--fail-on-broken] [--fail-on-critical]
    [--min-rating=excellent|good|needs_attention|none]
```

The single positional argument is the URL to scan. Everything else is optional.

## Option semantics

The CLI is a thin parser. Real validation happens in
`ScanConfig::fromCommandOptions()` in `src/DTO/ScanConfig.php`. That class
clamps values against the hard limits in `config/scannr.php`:

- `--depth` is clamped to `hard_max_depth` (default 10).
- `--max` is clamped to `hard_max_urls` (default 5000).
- `--timeout` is clamped to `timeout` (default 30s) — this is the **hard cap**;
  the CLI default of 5s is the *requested* timeout when the user doesn't
  specify one.
- `--delay-min` / `--delay-max` override `request_delay_min` /
  `request_delay_max` from config (defaults are 0/0 — no delay).
- `--strip-params` is *additive* — values are appended to the configured
  `tracking_params` list, not replacing it.

If a user passes a value over a hard cap, `ScanConfig` clamps silently and
appends a warning to the result array; `ScanSite::handle()` prints those
warnings before the scan starts.

## Filtering vs. scanning

`--scan-elements` and `--filter` are distinct:

- **`--scan-elements`** controls what the **crawler discovers and checks**.
  Setting `--scan-elements=a,img` makes the extractor only emit anchors and
  images. Everything else is ignored entirely.
- **`--filter`** controls what's **displayed** in the output. The crawler still
  checks everything; the formatter just hides the rest.

Use `--scan-elements` to speed up scans on large sites. Use `--filter` to
narrow a report without re-scanning.

`--status=ok|broken` is also a display filter, not a scan filter.

## Quality gates

Three flags can fail the command (exit code 1):

| Flag                  | Fails when                                                 |
| --------------------- | ---------------------------------------------------------- |
| `--fail-on-broken`    | any result has status 4xx/5xx or is a connection error     |
| `--fail-on-critical`  | `IntegrityScorer` produced any result with severity=CRITICAL |
| `--min-rating=GRADE`  | overall integrity grade is below GRADE                     |

Grades, in order: `excellent` (≥90) > `good` (≥75) > `needs_attention` (≥50)
> `critical` (<50). `--min-rating=none` (the default) disables this gate.

Quality gate enforcement is in `ScanSite::handle()` after formatting completes
— **not** in the entrypoint script. The GitHub Action wrapper just propagates
the artisan exit code. See `docs/claude/github-action.md`.

Test coverage: `tests/Unit/ExitCodeTest.php` is the canonical reference for
gate behavior. Any change to a gate must update those tests.

## JavaScript modes

- **`--js`** — render every page through Puppeteer / Chromium. Slow but
  thorough. Requires `npm install puppeteer` plus a Chromium binary the path
  is configured for (`config('scannr.js_rendering')`).
- **`--smart-js`** — start without JS, then if `SpaDetector` flags a page as
  an SPA (empty DOM + no links + framework signals), re-render that page with
  Puppeteer. Most efficient for mixed sites.

If neither flag is set, only the static HTML response body is parsed. SPAs
will appear as having zero links.

## Queued scans

`--queue` dispatches `ScanSiteJob` instead of running synchronously:

- requires `php artisan vendor:publish --tag=scannr-migrations && php artisan migrate`.
- creates a `ScanResult` row keyed by ULID.
- the row's `config` and `results` columns are JSON; the API consumer reads
  the row when the job finishes.
- `config('scannr.job_timeout')` (default 600s) bounds the job. Long crawls
  (`--max=5000`, `--depth=10`, `--js`) may need a higher value.

## Domain validation

If `APP_URL` is set in the host app, `ScanSite::validateDomain()` prints a
warning when the scanned URL's host doesn't match. This is **a warning, not
an error** — users intentionally scan domains that don't match `APP_URL`
(staging, third-party sites in CI). Don't change it to a hard failure.

`.scannr.yml` allowed-domain validation is a separate, GitHub-Action-only
check that lives in `entrypoint.sh`. See `docs/claude/github-action.md`.

## Adding a new option

1. Add it to `$signature` in `src/Console/Commands/ScanSite.php`.
2. Add a property + parsing branch in
   `ScanConfig::fromCommandOptions()`.
3. If it should propagate to the queued path, make sure the property
   round-trips through `ScanConfig::toArray()` / `fromArray()` — the job
   serializes `ScanConfig` to the DB.
4. If it should be exposed via the GitHub Action, add it to `action.yml`
   inputs and `entrypoint.sh`'s `get_input`/CMD assembly.
5. Add a test in `tests/Unit/ScanConfigTest.php` (parsing) plus wherever
   the option's behavior actually lives (e.g., `LinkExtractorTest` for a new
   extraction switch).
