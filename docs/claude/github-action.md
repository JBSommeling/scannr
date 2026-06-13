# GitHub Action

Scannr ships as a Docker-based GitHub Action so users can drop it into a CI
job without installing PHP. Three files define the contract:

- `action.yml` — public inputs/outputs (a versioned interface).
- `entrypoint.sh` — bash wrapper that maps inputs to `php artisan site:scan`.
- `Dockerfile` — container image with PHP 8.4 + Node + Chromium.
- `.env.action` — runtime Laravel config baked into the image.

## Inputs (action.yml)

Mirror the CLI 1:1, with kebab-case names. Defaults match the artisan defaults
where it makes sense, with one exception: `scan-elements` defaults to `all`.

| Input              | Default | Notes                                              |
| ------------------ | ------- | -------------------------------------------------- |
| `url`              | —       | required; validated against `.scannr.yml`          |
| `depth`            | `3`     |                                                    |
| `max`              | `300`   |                                                    |
| `timeout`          | `5`     | seconds                                            |
| `format`           | `table` | `table` / `json` / `csv`                           |
| `status`           | `all`   | display filter                                     |
| `filter`           | `all`   | display filter, element type                       |
| `scan-elements`    | `all`   | crawler discovery filter                           |
| `sitemap`          | `false` | boolean string                                     |
| `js`               | `false` | boolean string                                     |
| `smart-js`         | `false` | boolean string                                     |
| `no-robots`        | `false` | boolean string                                     |
| `advanced`         | `false` | boolean string                                     |
| `strip-params`     | —       | comma-separated, additive                          |
| `delay-min`        | —       | ms                                                 |
| `delay-max`        | —       | ms                                                 |
| `fail-on-broken`   | `false` | boolean string                                     |
| `fail-on-critical` | `false` | boolean string                                     |
| `min-rating`       | —       | `excellent` / `good` / `needs_attention`           |

**Renaming or removing an input is a breaking change** for any workflow that
uses `JBSommeling/scannr@v1`. Add inputs, don't remove them.

## Outputs (action.yml)

| Output            | Source                                                    |
| ----------------- | --------------------------------------------------------- |
| `exit-code`       | `$EXIT_CODE` from artisan                                 |
| `score`           | `score` key in `/tmp/scannr-ci-summary.json`              |
| `grade`           | `grade` key                                               |
| `broken-count`    | `broken_count` key                                        |
| `critical-count`  | `critical_count` key                                      |

The CI summary file is written by `ResultFormatterService` (look for the
`CiSummary` writing code). If you add an output, you have to write it in two
places: the summary writer and `entrypoint.sh`'s output-emission block.

## entrypoint.sh

Three responsibilities, in order:

1. **Read inputs.** GitHub Actions sets `INPUT_<NAME>` env vars but keeps
   hyphens (`INPUT_SMART-JS`). Bash variable names can't have hyphens, so
   we use `printenv` via the `get_input` helper. **Do not** revert to
   `$INPUT_SMART_JS` style — it silently returns empty for any hyphenated
   input. There's a real bug fix that established this pattern (see
   `fix/docker-js-rendering-hyphen-bug` in git history).

2. **Validate the domain.** If `.scannr.yml` exists in the workspace, parse
   its `allowed_domains:` list and fail (`exit 1`) if the input URL's host
   isn't on the list. This is the **only** hard domain check — the artisan
   command's `validateDomain` is a soft warning by design.

   `.scannr.yml` example:
   ```yaml
   allowed_domains:
     - example.com
     - staging.example.com
   ```
   Parsing is shell-grep; the format is intentionally trivial. Don't reach
   for a real YAML parser unless the config grows.

3. **Build and run the CLI command.** Boolean inputs use `[ "$X" = "true" ]`
   — anything other than the literal string `true` is treated as false.
   Output values come from `/tmp/scannr-ci-summary.json` after the run.

The final `exit $EXIT_CODE` propagates artisan's exit code, which already
reflects all quality gates. **Don't add gate logic in shell** — keep it in
PHP where it's testable.

## Dockerfile

Base image: `php:8.4-cli`. Installed:

- System: Chromium, Node.js 20, fonts, X11 libs.
- PHP extensions: xml, simplexml, pcntl.
- Composer deps (`--no-dev --optimize-autoloader`).
- Puppeteer with `PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true` — we use the
  system Chromium, not Puppeteer's bundled one (saves ~200MB).

Image-size discipline: every `apt-get install` should `&& rm -rf /var/lib/apt/lists/*`
in the same `RUN` layer. The image is fetched by every Action run; keep it
lean.

## .env.action

Runtime config for the container — gets copied to `/app/.env` during the
build. Notable:

- `APP_ENV=production`, `APP_DEBUG=false`.
- `DB_CONNECTION=sqlite` with in-memory DB. Queue support isn't used from the
  Action (no `--queue` exposed), so persistence isn't needed.
- `CHROME_PATH=/usr/bin/chromium`, `SCANNR_NODE_BINARY=/usr/bin/node` — points
  the JS-rendering services at the system binaries installed by the
  Dockerfile.
- `LOG_LEVEL=warning` to keep CI logs clean.

If you change `.env.action`, run a local Action test (`act` or push to a
test branch with a sample workflow) — the file isn't covered by unit tests.

## Common changes

| Change                                | Touch                                              |
| ------------------------------------- | -------------------------------------------------- |
| Add a CLI option to the Action        | `action.yml` + `entrypoint.sh` (input + CMD line)  |
| Add a new output                      | `ResultFormatterService` (write summary key) + `action.yml` + `entrypoint.sh` (read + emit) |
| Bump PHP version                      | `Dockerfile` base image + `composer.json` `require.php` + `.github/workflows/tests.yml` matrix |
| Change Chromium/Node                  | `Dockerfile` install layer + `.env.action` paths   |
| Adjust `.scannr.yml` parsing          | `entrypoint.sh` `validate_domain` function         |

## Release & versioning

The Action is referenced by users as `JBSommeling/scannr@v1` (or pinned to a
SHA). Bumping the major tag is a breaking-change signal — only do it when
inputs are renamed/removed or output semantics change. Minor changes (new
optional inputs, new outputs, performance) are fine on the existing major.
