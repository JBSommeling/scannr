# Testing

PHPUnit 11 via Orchestra Testbench. Run with `composer test` from the repo
root. CI runs the same command — see `.github/workflows/tests.yml`.

## What's covered

Every service in `src/Services/` has a matching `tests/Unit/<Name>Test.php`.
Every CLI option behavior is covered in either `ScanConfigTest` (parsing) or
`ExitCodeTest` (quality gates). Every enum-derived rule (flags → severity,
flags → issue type) is covered in `SeverityEvaluatorConfidenceTest`,
`SeverityEvaluatorCdnTest`, and `IntegrityScorerTest`.

Live HTTP is exercised by exactly one test: `SitemapLiveTest`. Everything else
mocks `HttpChecker` and friends. If a new test needs network, route it through
a mock or extend the live test (and accept that it'll be flaky offline).

## Conventions

- All test classes extend `Tests\TestCase` (`tests/TestCase.php`), which boots
  an Orchestra Testbench app with the package's service provider registered.
- `setUp()` resolves services from the container via `app()` or
  `$this->app->make()`. Don't `new` services — that bypasses the singleton
  bindings and you'll get inconsistent behavior.
- Use `@DataProvider` (PHPUnit 11 attribute style) for parametrized scenarios.
  `IntegrityScorerTest` is a good template.
- Naming: test methods describe behavior (`it_flags_localhost_as_developer_leftover`).
  Some older tests use `testXxx` — both are acceptable, prefer the former.

## Where to put new tests

| Adding…                              | Goes in…                                       |
| ------------------------------------ | ---------------------------------------------- |
| A new CLI option                     | `ScanConfigTest.php` (parsing + clamping)      |
| A new quality-gate behavior          | `ExitCodeTest.php`                             |
| A new `LinkFlag` set by extraction   | `LinkExtractorTest.php`                        |
| A new `LinkFlag` set by HTTP outcome | `LinkFlagServiceTest.php` or `HttpCheckerTest` |
| A new severity/confidence rule       | `SeverityEvaluatorConfidenceTest.php`          |
| A new `IssueType` or penalty change  | `IntegrityScorerTest.php`                      |
| A new noise filter / formatter rule  | `ResultFormatterServiceTest.php`               |
| A new sitemap format                 | `SitemapServiceTest.php` (mocked)              |
| A new CI summary field               | `CiSummaryTest.php`                            |

## Patterns to follow

**Use the public API of services.** Tests should construct realistic input
arrays and call `ScannerService::process*` / `IntegrityScorer::calculate` /
`ResultFormatterService::format`, not poke private state. The few places
reflection is used (e.g., `ScanSiteTest` for protected `validateDomain`) are
exceptional — don't expand that pattern.

**Mock at the boundary.** Mock `HttpChecker`, not Guzzle. Mock
`BrowsershotFetcher`, not Puppeteer. The point of the service layer is that
the seam already exists.

**Result shape is convention, not type.** Results are plain associative
arrays (`['url' => ..., 'status' => ..., 'type' => ..., 'analysis' => [...]]`).
There's no DTO. When you add a key, every test that builds a fake result
needs updating. `grep` for `'analysis' =>` to find them.

**Test the priority order in `IntegrityScorer`.** When you add or move a
branch in `resolveIssueType()`, write a case where multiple flags are set
and assert which issue type wins. This is the single highest-leverage type
of test in the project.

## Running a single test

```bash
vendor/bin/phpunit --filter it_flags_localhost_as_developer_leftover
vendor/bin/phpunit tests/Unit/IntegrityScorerTest.php
```

`composer test` runs the full suite — fine for CI, slow for inner-loop dev.

## CI

`.github/workflows/tests.yml` matrix:

- PHP 8.4 with extensions: dom, curl, libxml, mbstring, zip, pcntl, sqlite,
  bcmath, simplexml.
- Composer deps cached by lockfile hash.
- Runs `composer test`.

If you add a PHP extension dependency, update the workflow's
`extensions:` list **and** `composer.json`'s `require` section. Both, or the
host app fails to install.

## Things tests intentionally don't cover

- **Live HTTP.** Only `SitemapLiveTest` hits the network; everything else
  uses mocks.
- **Puppeteer / Chromium.** `BrowsershotFetcherTest` mocks the spatie wrapper.
  Real headless browser behavior is exercised manually + in CI on the
  GitHub Action's own integration test (if present).
- **Database persistence.** `ScanSiteJobTest` exercises the model transitions
  but doesn't validate every column. The migration is plain Laravel and
  trusts framework guarantees.
