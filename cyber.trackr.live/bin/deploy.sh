#!/usr/bin/env bash
# Production deploy steps for Cyber Trackr.
#
# Run from the Symfony app root after rsync:
#
#   cd /path/to/cyber.trackr.live
#   ./bin/deploy.sh
#
# Order matters here. Things to know:
#   1. The toc / sidecar indexes (stig, scap, vulns, companion-ZIP,
#      bulk-download) are rebuilt HERE from prod's XML, not shipped from
#      dev: prod's nightly cron pulls STIG/SCAP bundles dev never has,
#      and dev's indexes would drop them from the site until the next
#      cron. Same commands, same order as refresh-data.sh steps 4-6.
#   2. app:search:rebuild --full runs next because it reads
#      stig_toc.json to pick the latest release per title, and because
#      it can take a while — we'd rather have a stale cache while it
#      runs than have it blow up halfway through against a freshly-
#      cleared cache that's missing compiled services.
#   3. cache:clear --env=prod has to run BEFORE any prod request hits
#      the new code, otherwise Symfony serves from the old compiled
#      container and crashes when it can't resolve a new service.
#   4. cache:clear does NOT clear the app cache pool. Since Symfony 7.4
#      cache.app lives in var/share/prod/pools/app, outside the cache
#      dir cache:clear swaps out. The search index caches its postings
#      and shards there (IndexStore), so a stale entry would pair an old
#      posting map with new shards and return the wrong documents.
#      cache:pool:clear cache.app runs from an EXIT trap so it happens
#      even when a step above fails and `set -e` aborts the deploy.
#   5. The IndexNow ping is best-effort — any failure (no key file,
#      network blip, IndexNow service hiccup) MUST NOT fail the
#      deploy. We wrap it in `|| true`.
#   6. fix-perms.sh runs LAST, after cache:clear has regenerated the
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

# Runs on every exit, success or failure (see note 4 above).
clear_app_pool() {
    echo
    echo "[trap] Clearing the app cache pool (search postings + shards) …"
    "$PHP" bin/console cache:pool:clear cache.app --env=prod || true
}
trap clear_app_pool EXIT

echo "──────────────────────────────────────────"
echo "  Cyber Trackr deploy"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/7] Rebuilding stig_toc.json + scap_toc.json + vulns_toc.json …"
"$PHP" bin/console app:stig:rebuild

echo
echo "[2/7] Rebuilding companion-ZIP index for STIG pages …"
"$PHP" bin/console app:companion-zip:rebuild-index

echo
echo "[3/7] Rebuilding bulk-download index (XML/ZIP presence + sizes) …"
"$PHP" bin/console app:bulk-download:rebuild-index

echo
echo "[4/7] Rebuilding the inverted search index (this takes a minute) …"
"$PHP" bin/console app:search:rebuild --full

echo
echo "[5/7] Clearing the prod cache …"
"$PHP" bin/console cache:clear --env=prod

echo
echo "[6/7] Pinging IndexNow about recently-changed pages (best-effort) …"
"$PHP" bin/console app:indexnow:ping --recent --within=30 || true

echo
echo "[7/7] Resetting PHP-site permissions …"
./bin/fix-perms.sh

echo
echo "──────────────────────────────────────────"
echo "  Deploy complete."
echo "──────────────────────────────────────────"
