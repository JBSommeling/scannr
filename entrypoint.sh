#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# Domain validation: if the repo contains a .scannr.yml, validate the URL
# against the allowed domains listed in it.
# ---------------------------------------------------------------------------
validate_domain() {
    local url="$1"
    local config_file="/github/workspace/.scannr.yml"

    # Extract host from URL
    local host
    host=$(echo "$url" | sed -E 's|^https?://||' | sed -E 's|[:/].*||' | tr '[:upper:]' '[:lower:]')

    # Strip www. for comparison
    local normalized_host
    normalized_host=$(echo "$host" | sed 's/^www\.//')

    # If .scannr.yml exists, validate against allowed domains
    if [ -f "$config_file" ]; then
        # Parse allowed_domains from YAML (simple grep-based parsing)
        local allowed
        allowed=$(grep -A 100 'allowed_domains:' "$config_file" 2>/dev/null | grep '^\s*-' | sed 's/.*-\s*//' | tr -d ' "'"'" | tr '[:upper:]' '[:lower:]')

        if [ -n "$allowed" ]; then
            local match_found=false
            while IFS= read -r domain; do
                local normalized_domain
                normalized_domain=$(echo "$domain" | sed 's/^www\.//')
                if [ "$normalized_host" = "$normalized_domain" ]; then
                    match_found=true
                    break
                fi
            done <<< "$allowed"

            if [ "$match_found" = false ]; then
                echo "::error::URL domain '$host' is not in the allowed_domains list in .scannr.yml"
                echo "::error::Allowed domains: $allowed"
                exit 1
            fi
        fi
    fi
}

# ---------------------------------------------------------------------------
# Build the artisan command
# ---------------------------------------------------------------------------
CMD="php /app/artisan site:scan"

# Required: URL
if [ -z "$INPUT_URL" ]; then
    echo "::error::The 'url' input is required."
    exit 1
fi

# Validate domain against .scannr.yml
validate_domain "$INPUT_URL"

CMD="$CMD $INPUT_URL"

# Optional flags with values
[ -n "$INPUT_DEPTH" ]          && CMD="$CMD --depth=$INPUT_DEPTH"
[ -n "$INPUT_MAX" ]            && CMD="$CMD --max=$INPUT_MAX"
[ -n "$INPUT_TIMEOUT" ]        && CMD="$CMD --timeout=$INPUT_TIMEOUT"
[ -n "$INPUT_FORMAT" ]         && CMD="$CMD --format=$INPUT_FORMAT"
[ -n "$INPUT_STATUS" ]         && CMD="$CMD --status=$INPUT_STATUS"
[ -n "$INPUT_FILTER" ]         && CMD="$CMD --filter=$INPUT_FILTER"
[ -n "$INPUT_SCAN_ELEMENTS" ]  && CMD="$CMD --scan-elements=$INPUT_SCAN_ELEMENTS"
[ -n "$INPUT_STRIP_PARAMS" ]   && CMD="$CMD --strip-params=$INPUT_STRIP_PARAMS"

# Boolean flags
[ "$INPUT_SITEMAP" = "true" ]   && CMD="$CMD --sitemap"
[ "$INPUT_JS" = "true" ]       && CMD="$CMD --js"
[ "$INPUT_SMART_JS" = "true" ] && CMD="$CMD --smart-js"
[ "$INPUT_NO_ROBOTS" = "true" ] && CMD="$CMD --no-robots"
[ "$INPUT_ADVANCED" = "true" ] && CMD="$CMD --advanced"

echo "::group::Scannr Site Scan"
echo "Running: $CMD"
echo ""

# Run the scan and capture exit code
set +e
eval $CMD
EXIT_CODE=$?
set -e

echo "::endgroup::"

# Set output
echo "exit-code=$EXIT_CODE" >> "$GITHUB_OUTPUT"

# Fail the step if scan found broken links (non-zero exit)
if [ "$INPUT_FAIL_ON_BROKEN" = "true" ] && [ $EXIT_CODE -ne 0 ]; then
    echo "::error::Scannr detected issues (exit code: $EXIT_CODE)"
    exit $EXIT_CODE
fi

exit 0
