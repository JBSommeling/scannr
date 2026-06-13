# Configuration

The package's entire tunable surface lives in `config/scannr.php`. Defaults
here are merged into the host app's config at boot via
`ScannrServiceProvider::register()`. When users want to override, they run
`php artisan vendor:publish --tag=scannr-config`.

## Sections at a glance

| Section                  | Key                          | What it controls                                          |
| ------------------------ | ---------------------------- | --------------------------------------------------------- |
| Tracking params          | `tracking_params`            | Query params stripped during URL normalization (supports `*` suffix) |
| User agent               | `user_agent`                 | `User-Agent` header sent on every request                 |
| Rate limiting            | `request_delay_min/max`      | Random delay (ms) between requests                        |
| Timeout                  | `timeout`                    | **Hard cap** on `--timeout` CLI option (seconds)          |
| Hard limits              | `hard_max_depth`, `hard_max_urls` | Caps on `--depth` and `--max`                        |
| JS rendering             | `js_rendering.*`             | Node/Chrome binaries + render timeout (env-driven)        |
| Queue                    | `job_timeout`                | Max seconds for a `ScanSiteJob`                           |
| Download extensions      | `download_extensions`        | Extensions treated as downloads in JS string extraction   |
| Form keywords            | `form_keywords`              | Substrings that mark a JS endpoint as form-related        |
| Rate-limit handling      | `rate_limit.*`               | 429 backoff, Retry-After, abort threshold                 |
| Noise URLs               | `noise_urls.*`               | What gets hidden unless `--advanced`                      |
| CDN prefixes             | `cdn_prefixes`               | Subdomains that flag a URL as a CDN asset                 |
| Integrity scoring        | `integrity_scoring.*`        | Penalty model, dampening, categories, grade thresholds    |
| External platforms       | `external_platforms`         | Domains where 403/405 is treated as bot protection        |

## The hard limits are not negotiable from the CLI

```php
'hard_max_depth' => 10,
'hard_max_urls'  => 5000,
'timeout'        => 30,
```

`ScanConfig::fromCommandOptions()` clamps CLI options to these. Users can't
pass `--max=10000` and expect it to work. If the cap genuinely needs to move,
edit the config and adjust `tests/Unit/ScanConfigTest.php`.

## JS rendering — env-only

```php
'js_rendering' => [
    'node_binary'        => env('SCANNR_NODE_BINARY'),
    'npm_binary'         => env('SCANNR_NPM_BINARY'),
    'chrome_path'        => env('CHROME_PATH'),
    'node_modules_path'  => env('SCANNR_NODE_MODULES_PATH'),
    'timeout'            => 30,
],
```

These four paths are not surfaced as CLI options. They're entirely env-driven
because the right paths depend on the host (dev laptop vs. Docker vs.
production server). Inside the GitHub Action container, `.env.action` sets
them to `/usr/bin/node` and `/usr/bin/chromium`.

## Noise URL filtering

By default, the output hides three categories of "noise":

```php
'noise_urls' => [
    'namespace_domains' => ['www.w3.org', 'schema.org', ...],
    'detect_preconnect' => true,    // bare-domain <link> elements
    'framework_error_patterns' => [
        '#^https?://react\.dev/errors#',
        '#^https?://vuejs\.org/error-reference#',
        // ...
    ],
    'exact' => [],
    'prefix' => [],
],
```

These exist because crawlers naively report W3C namespace URIs as "200 OK
external links" — true but useless. `--advanced` switches them back on.

When users complain about false positives showing in the report, the answer
is usually to extend one of these lists, not to add CLI flags.

## CDN prefix detection

```php
'cdn_prefixes' => ['cdn', 'static', 'assets', 'media', 'img', ...],
```

When the scanned host is, say, `example.com` and a link points to
`cdn.example.com`, that link gets the `CDN_ASSET` flag. A 4xx on a CDN asset
becomes the `cdn_asset` issue type (penalty 2) instead of `status_4xx_internal`
(penalty 10) — because CDNs commonly block bot user agents.

Adding a new prefix here costs nothing. Removing one is risky — sites may rely
on the existing list to keep their integrity score sane.

## Integrity scoring shape

```php
'integrity_scoring' => [
    'penalties' => [
        'developer_leftover'           => 12,
        'status_4xx_internal'          => 10,
        'status_5xx'                   => 10,
        'connection_error'             => 10,
        'form_endpoint_404'            => 10,
        'malformed_url'                => 8,
        'status_4xx_external_platform' => 5,
        'excessive_redirects'          => 5,
        'http_on_https'                => 4,
        'redirect_chain'               => 3,
        'timeout'                      => 3,
        'bot_protection'               => 2,
        'cdn_asset'                    => 2,
        'rate_limited'                 => 1,
    ],
    'confidence_multipliers' => ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3],
    'category_penalty_multiplier' => 2.5,
    'dampening' => [
        'tier_1_max'    => 1,    // 1st occurrence: full penalty
        'tier_2_max'    => 5,    // 2nd–5th: tier_2_factor
        'tier_2_factor' => 0.5,
        'tier_3_factor' => 0.25, // 6th+: 25%
    ],
    'categories' => [
        'link_integrity'    => ['weight' => 0.45, 'types' => [...]],
        'security_hygiene'  => ['weight' => 0.20, 'types' => [...]],
        // ...
    ],
    'grades' => [
        'excellent' => 90,
        'good' => 75,
        'needs_attention' => 50,
    ],
],
```

Read `docs/claude/flags-and-scoring.md` before changing any of these — the
priority order in `IntegrityScorer::resolveIssueType()` interacts with the
penalty values in non-obvious ways.

**Note:** category `weight` is **not currently applied** to the overall score
calculation. It's an intent declaration that exists for documentation purposes
and possible future use. Overall score is a plain sum of all effective
penalties subtracted from 100. The category multiplier (`2.5`) only affects
per-category sub-scores, never the overall.

## Adding a new config key

1. Add it to `config/scannr.php` with defaults plus a header comment block
   matching the existing style (the comments are user-facing).
2. Wherever it's read, fail loudly if missing — see `IntegrityScorer::getConfig()`
   for the pattern (throws `\RuntimeException` if the section is missing).
   Silent fallbacks via `??` are fine for individual sub-keys but not for
   whole sections.
3. Add a test if the value influences behavior in a non-trivial way.
4. Update the README's "Configuration" section so end users discover it.
