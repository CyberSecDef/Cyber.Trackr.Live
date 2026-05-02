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
#   4. The IndexNow ping is intentionally last and best-effort — any
#      failure (no key file, network blip, IndexNow service hiccup)
#      MUST NOT fail the deploy. We wrap it in `|| true`.

set -euo pipefail
cd "$(dirname "$0")/.."

echo "──────────────────────────────────────────"
echo "  Cyber Trackr deploy"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/4] Rebuilding the inverted search index (this takes a minute) …"
./bin/console app:search:rebuild --full

echo
echo "[2/4] Clearing the prod cache …"
php bin/console cache:clear --env=prod

echo
echo "[3/4] Clearing the dev cache …"
php bin/console cache:clear --env=dev

echo
echo "[4/4] Pinging IndexNow about recently-changed pages (best-effort) …"
php bin/console app:indexnow:ping --recent --within=30 || true

echo
echo "──────────────────────────────────────────"
echo "  Deploy complete."
echo "──────────────────────────────────────────"
