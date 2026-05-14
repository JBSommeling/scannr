# Flags & Scoring

The integrity score is the headline output. This document explains the data
flow from observation → flag → issue type → penalty → score, and the
conventions for adding to that flow.

## Three enums, three jobs

| Enum         | File                          | Role                                                          |
| ------------ | ----------------------------- | ------------------------------------------------------------- |
| `LinkFlag`   | `src/Enums/LinkFlag.php`      | Orthogonal observations — multiple can be set per result      |
| `IssueType`  | `src/Enums/IssueType.php`     | Resolved penalty category — **at most one** per result        |
| `Severity`   | `src/Enums/Severity.php`      | `CRITICAL` / `WARNING` / `INFO` — display + quality gates     |
| `Confidence` | `src/Enums/Confidence.php`    | `HIGH` / `MEDIUM` / `LOW` — multiplies penalty                |

Flags are facts. Issue types are decisions. Severity/confidence are
presentation + scoring inputs.

## LinkFlag cases

Grouped by intent (see the enum file for canonical comments):

**A. Discovery — how was the link found?**
- `STATIC_HTML` — direct DOM extraction.
- `RUNTIME_RENDERED` — discovered via headless browser.
- `INDIRECT_REFERENCE` — URL contains template patterns / path params.
- `DETECTED_IN_JS_BUNDLE` — extracted from inline or external JS.

**B. Platform characteristics**
- `EXTERNAL_PLATFORM` — domain matches `config('scannr.external_platforms')`.
- `BOT_PROTECTION` — 403/405 from a known platform.
- `CDN_ASSET` — URL on a `cdn_prefixes` subdomain.
- `RATE_LIMITED` — 429 received.

**C. Technical anomalies**
- `MALFORMED_URL` — template literals, control chars, etc.
- `DEVELOPER_LEFTOVER` — localhost / .local / .test / 127.0.0.1.
- `HTTP_ON_HTTPS` — redirect downgrade.
- `REDIRECT_CHAIN` — 2+ hops.
- `EXCESSIVE_REDIRECTS` — 5+ hops or loop.

**D. Validation outcome**
- `STATUS_4XX`, `STATUS_5XX`, `TIMEOUT`, `CONNECTION_ERROR`, `UNVERIFIED`.

**E. Endpoint type**
- `FORM_ENDPOINT` — POST-based endpoint, treated specially by the scorer.

## Where flags get attached

`LinkFlagService` is the only writer of flags. It's called from
`ScannerService::processInternalUrl` / `processExternalUrl` (and indirectly
from `LinkExtractor` for some discovery-time flags like `INDIRECT_REFERENCE`).

`SeverityEvaluator::evaluate()` is the only reader that decides Severity +
Confidence from a flag set. Both files are short and self-contained — read
them before adding logic.

## Issue type resolution (the priority chain)

`IntegrityScorer::resolveIssueType()` is the heart of scoring. It walks a
priority-ordered chain of conditions and returns the first match — at most
one issue type per result.

The order is, in plain English:

1. **Form endpoints first.** A `FORM_ENDPOINT` with `STATUS_4XX` is either a
   *real* 404 → `FORM_ENDPOINT_404` (penalty 10) — or it's one of the
   "healthy form" statuses (400, 401, 403, 405, 419, 422, 429) which are
   normal for endpoints expecting a valid POST body → `null`, no penalty.
2. **Developer leftover** beats everything — a localhost link is broken
   regardless of HTTP status.
3. **Connection error** (without external platform) → infrastructure problem.
4. **5xx** → server side.
5. **Internal 4xx without bot-protection or CDN signal** → genuinely broken.
6. **4xx on a CDN subdomain** → low-penalty `CDN_ASSET` (likely bot blocking).
7. **Malformed URL** → unusable.
8. **Excessive redirects** → loops or chains of 5+.
9. **HTTPS downgrade** → security.
10. **Timeout** → unhealthy.
11. **Redirect chain** (2–4 hops) → cosmetic but tracked.
12. **Bot protection** → low-confidence, low-penalty.
13. **Rate limited** → minimal.
14. **External 4xx with platform flag but no bot protection** → likely
    genuine 404 on the platform (e.g., a deleted LinkedIn post).

Order is **load-bearing**. Changing it can flip a result's penalty from 10
(internal 4xx) to 2 (CDN asset) or 0 (healthy form). Always update
`tests/Unit/IntegrityScorerTest.php` when you touch this method.

## Score math

For each result:

```
effectivePenalty = basePenalty × confidenceMultiplier × dampeningFactor
```

- `basePenalty` from `config('scannr.integrity_scoring.penalties')`.
- `confidenceMultiplier`: high 1.0, medium 0.6, low 0.3.
- `dampeningFactor`:
  - 1st occurrence of this issue type: **1.0** (full penalty).
  - 2nd–5th occurrences: **0.5**.
  - 6th and beyond: **0.25**.

Dampening prevents one repeated issue type from collapsing the score —
sites with 50 internal 4xx would otherwise score wildly negative.

Then:

```
overallScore = 100 − Σ effectivePenalty
categoryScore[c] = max(0, 100 − Σ effectivePenalty[c] × category_penalty_multiplier)
                                                       ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                                                       (2.5 by default)
```

The category multiplier *amplifies* per-category sub-scores so a small number
of issues within a single category is still visible in that sub-score, without
affecting the overall score. The category `weight` field in config is
**currently unused** in the overall calculation.

Overall score is **not clamped to ≥0** at the model level — pathological
result sets can yield negative numbers. The formatter handles display.

## Grades

```
≥ 90  → Excellent
≥ 75  → Good
≥ 50  → Needs Attention
< 50  → Critical
```

Thresholds in `config('scannr.integrity_scoring.grades')`.

## Adding a new LinkFlag

1. Add the case to `src/Enums/LinkFlag.php` with a docblock.
2. Decide where it gets set:
   - discovery-time → `LinkExtractor` calls `LinkFlagService::flagX(...)`.
   - validation-time → `ScannerService` sets it after `HttpChecker` returns.
3. Decide whether `SeverityEvaluator` should treat it as critical / warning,
   and at what confidence. Add the rule there.
4. **Decide whether it should produce a penalty.** A flag without a matching
   `IssueType` won't change the score. If it should:
   - add a case to `src/Enums/IssueType.php`.
   - add a `penalties` entry in `config/scannr.php`.
   - add a branch (in the correct priority position) in
     `IntegrityScorer::resolveIssueType()`.
   - add the issue type to a category in `config('scannr.integrity_scoring.categories')`.
5. Test:
   - `tests/Unit/LinkFlagServiceTest.php` — assignment logic.
   - `tests/Unit/SeverityEvaluatorConfidenceTest.php` — severity/confidence
     derivation.
   - `tests/Unit/IntegrityScorerTest.php` — penalty + score impact.

Pattern: every existing flag has a corresponding test asserting the
flag-to-issue-type mapping. Don't merge a new flag without one.

## Common pitfalls

- **A flag with no issue type is invisible to scoring.** Setting `BOT_PROTECTION`
  without any `STATUS_4XX` won't fire `BOT_PROTECTION` issue type — the
  resolver requires the matching flag combination. Read
  `resolveIssueType()` before assuming.
- **Adding a high-priority issue type without thinking about precedence can
  silently downgrade penalties.** If you insert a new case before
  `STATUS_4XX_INTERNAL` and many results now match the new case, internal
  4xx errors stop being counted as such.
- **Healthy form statuses are intentionally `null`-returning.** Don't "fix"
  the early `return null` in `resolveIssueType()` — it's the only way 401/405
  on a contact form doesn't tank a score.
