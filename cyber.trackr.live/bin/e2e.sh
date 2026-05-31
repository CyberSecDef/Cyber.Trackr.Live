#!/usr/bin/env bash
# Run the Playwright e2e suite. Prefers a system Chromium (via CHROMIUM_BIN)
# so the suite works without downloading Playwright's bundled browser.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -z "${CHROMIUM_BIN:-}" ]; then
    for c in /usr/bin/chromium-browser /usr/bin/chromium /snap/bin/chromium /usr/bin/google-chrome; do
        if [ -x "$c" ]; then CHROMIUM_BIN="$c"; break; fi
    done
fi
export CHROMIUM_BIN
echo "Using CHROMIUM_BIN=${CHROMIUM_BIN:-<playwright bundled>}"

exec npx playwright test "$@"
