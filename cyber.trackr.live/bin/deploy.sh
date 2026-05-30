#!/usr/bin/env bash
# Production deploy steps for Cyber Trackr.
#
# Run from the Symfony app root after rsync:
#
#   cd /path/to/cyber.trackr.live
#   ./bin/deploy.sh
#
# Order matters here. Things to know:
#   1. app:search:rebuild --full goes first because it can take a while
#      and we'd rather have a stale cache while it runs than have it
#      blow up halfway through against a freshly-cleared cache that's
#      missing compiled services.
#   2. cache:clear --env=prod has to run BEFORE any prod request hits
#      the new code, otherwise Symfony serves from the old compiled
#      container and crashes when it can't resolve a new service.
#   3. cache:clear --env=dev is included so that future tail-of-the-log
#      debugging from a `console --env=dev` session doesn't fight an
#      old dev container either.
#   4. The IndexNow ping is best-effort — any failure (no key file,
#      network blip, IndexNow service hiccup) MUST NOT fail the
#      deploy. We wrap it in `|| true`.
#   5. fix-perms.sh runs LAST, after cache:clear has regenerated the
#      cache files with whatever umask the deploy user has. It must
#      be invoked from the site root (the dir containing ./bin/) —
#      this script's `cd "$(dirname "$0")/.."` above guarantees that.
#      Easy to forget when deploying by hand; baked in here so it
#      can't be skipped.

set -euo pipefail
cd "$(dirname "$0")/.."

# DreamHost's default CLI php is 8.2, older than composer.json requires, so
# default to the php84 build. Override with `PHP=php ./bin/deploy.sh` (or any
# path) on hosts where the default php is already current.
PHP="${PHP:-/usr/local/php84/bin/php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "[deploy] PHP binary not found: $PHP (set PHP=/path/to/php)" >&2
    exit 1
fi

echo "──────────────────────────────────────────"
echo "  Cyber Trackr deploy"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/5] Rebuilding the inverted search index (this takes a minute) …"
"$PHP" bin/console app:search:rebuild --full

echo
echo "[2/5] Clearing the prod cache …"
"$PHP" bin/console cache:clear --env=prod

echo
echo "[3/5] Clearing the dev cache …"
"$PHP" bin/console cache:clear --env=dev

echo
echo "[4/5] Pinging IndexNow about recently-changed pages (best-effort) …"
"$PHP" bin/console app:indexnow:ping --recent --within=30 || true

echo
echo "[5/5] Resetting PHP-site permissions …"
./bin/fix-perms.sh

echo
echo "──────────────────────────────────────────"
echo "  Deploy complete."
echo "──────────────────────────────────────────"
