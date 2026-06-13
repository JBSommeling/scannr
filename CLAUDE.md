# Scannr — Claude Code Guide

Scannr is a Laravel 12 / PHP 8.4 package (`jbsommeling/scannr`) that crawls
websites to detect broken links, redirect chains, HTTPS downgrades, and similar
link-health issues. It also ships as a Docker-based GitHub Action for CI/CD
quality gates. JS-rendered SPAs are supported via spatie/browsershot.

This file is the always-loaded entry point. Topic-specific detail lives under
`docs/claude/` — load those on demand.

## Layout at a glance

```
src/
  Console/Commands/ScanSite.php   single artisan command: `site:scan`
  ScannrServiceProvider.php       wires singletons, merges config, publishes
  Services/                       crawler, http checker, link extractor, ...
  Facades/                        Scanner, Http, Links, Url, Sitemap, ScanStats
  DTO/                            ScanConfig, LinkAnalysis, IntegrityScoreResult
  Enums/                          LinkFlag, IssueType, Severity, Confidence
  Contracts/OutputInterface.php   formatter contract
  Jobs/ScanSiteJob.php            queued background scan
  Models/ScanResult.php           Eloquent model for queued scans (ULID pk)
config/scannr.php                 package config (tracking, rate, scoring, ...)
tests/Unit/                       PHPUnit, inherits Orchestra Testbench
action.yml + entrypoint.sh        GitHub Action wrapper
Dockerfile + .env.action          container build + runtime config
```

## Key commands

- `composer test` — run PHPUnit (`tests/Unit/`).
- `php artisan site:scan <url> [options]` — primary entry point.
- `php artisan vendor:publish --tag=scannr-config` — publish config.
- `php artisan vendor:publish --tag=scannr-migrations` — publish queue migration.

## When to load which detail file

Read only what the current task needs.

| Task                                         | Load                              |
| -------------------------------------------- | --------------------------------- |
| Understand the crawl/request flow            | `docs/claude/architecture.md`     |
| Change CLI options or quality-gate behavior  | `docs/claude/commands.md`         |
| Tune defaults, penalties, noise filtering    | `docs/claude/configuration.md`    |
| Add a new flag, issue type, or score change  | `docs/claude/flags-and-scoring.md`|
| Add or modify tests                          | `docs/claude/testing.md`          |
| Change CI Action behavior, inputs, container | `docs/claude/github-action.md`    |

## Hard rules

- **PHP 8.4 / Laravel 12 only.** `composer.json` constrains both — don't add
  syntax or APIs older PHP can run but newer Laravel can't.
- **No new dependencies without a stated reason.** This is a library; every
  `require` becomes the user's problem too.
- **Don't bypass the service provider.** All services are registered as
  singletons in `src/ScannrServiceProvider.php`. New services follow the same
  pattern.
- **Network in tests is opt-in.** `tests/Unit/SitemapLiveTest.php` is the only
  test allowed to hit live HTTP; everything else mocks. See
  `docs/claude/testing.md`.
- **Don't break the GitHub Action contract.** `action.yml` inputs/outputs are
  a public interface — renaming or removing is a breaking change. See
  `docs/claude/github-action.md`.
- **Domain validation in the CLI is a warning, not an error.** Users
  intentionally scan domains that don't match `APP_URL`.

## Conventions

- Strict types: `declare(strict_types=1);` at the top of every PHP file in
  `src/`.
- Constructor-promoted dependencies for services; resolve through the container,
  not `new`.
- Public service methods are exposed via Facades in `src/Facades/`. If you add a
  new public method, decide whether it belongs on a facade.
- Enums are backed (`: string`) so they round-trip through JSON / queue
  serialization without custom casts.
- Result rows are plain associative arrays produced by `ScannerService`; the
  shape is read by `ResultFormatterService` and `IntegrityScorer`. Adding a
  field means updating both sides.

## Where things commonly go wrong

- **Adding a new `LinkFlag`** without updating `SeverityEvaluator` and
  `IntegrityScorer` config — the flag will be set but invisible in output and
  scoring. See `docs/claude/flags-and-scoring.md`.
- **Adding a CLI option** without adding it to `ScanConfig::fromCommandOptions`
  — the option parses but never reaches the crawler.
- **Changing the queued result shape** without a migration — `ScanResult.results`
  is a JSON column; old rows must still deserialize.
- **Touching `entrypoint.sh`** with bash-isms that don't survive Alpine /
  minimal containers. Stick to POSIX where possible and quote everything.
