#!/usr/bin/env bash
# Daily data refresh for Cyber Trackr (runs on prod, not dev).
#
# Designed to be invoked by cron at 04:00 local time so the live site
# picks up new KEV entries and any CVE/IAVM/CTO refs that have been
# added to the STIG/SCAP corpus since the last ship — without needing
# a full dev → rsync deploy.
#
# Suggested crontab entry (run `crontab -e` on prod):
#
#   0 4 * * * /home/dh_t7zn6y/cyber.trackr.live/bin/refresh-data.sh \
#       >> /home/dh_t7zn6y/cyber.trackr.live/var/log/refresh-data.log 2>&1
#
# Order:
#   1. app:kev:refresh                — pulls the CISA KEV catalog.
#                                       Network-dependent; runs first
#                                       so we fail fast if the feed is
#                                       unreachable.
#   2. app:disa:sync-stig             — pulls any new STIG bundles
#   3. app:disa:sync-scap               from cyber.mil into the local
#                                       archive. Network-dependent;
#                                       wrapped in `|| true` so a
#                                       transient DISA outage doesn't
#                                       kill the cron and skip the
#                                       downstream index rebuilds.
#                                       Steady-state cost is small
#                                       (only fetches new ZIPs).
#   4. app:stig:rebuild               — meta: rebuilds stig_toc.json,
#                                       scap_toc.json, and vulns_toc.json
#                                       from the freshly-synced XML. The
#                                       /stig and /scap controllers do
#                                       lazy ADD of new files on request,
#                                       but only a full rebuild detects
#                                       changes to existing XMLs (e.g.
#                                       updated severity counts) and
#                                       refreshes the sidecar indexes
#                                       below from current data.
#   5. app:companion-zip:rebuild-index — re-scans resources/data/zips/
#                                       and rebuilds the XML→ZIP map
#                                       used to surface DISA companion
#                                       PDFs on each STIG page. ~5s
#                                       for ~1200 ZIPs. Idempotent.
#   6. app:bulk-download:rebuild-index — stat()s every STIG XML and
#                                       companion ZIP to build the
#                                       presence/size sidecar used by
#                                       /stig/bulk. Depends on steps 4+5.
#   7. app:search:rebuild              — incremental sync of the
#                                       inverted search index so newly-
#                                       synced STIGs/SCAPs are
#                                       searchable before the next ship.
#                                       (Deploy still does --full.)
#   8. app:indexnow:ping              — best-effort nudge to Bing/
#                                       Yandex/etc. about new STIG
#                                       versions (--recent --within=1)
#                                       plus the vulns landing pages and
#                                       sitemap. Wrapped in `|| true` so
#                                       a transient IndexNow failure
#                                       can't fail the cron run and
#                                       skip tomorrow's email.
#
# Cron runs with a minimal PATH, so we set one explicitly and use
# absolute cwd via $(dirname "$0")/.. — same pattern as ship.sh /
# deploy.sh. Errors surface via the log file referenced above.

set -euo pipefail
export PATH=/usr/local/bin:/usr/bin:/bin
cd "$(dirname "$0")/.."

# DreamHost's default CLI php is 8.2, older than composer.json requires, so
# default to the php84 build. Override with `PHP=php ./bin/refresh-data.sh`
# (or any path) on hosts where the default php is already current.
PHP="${PHP:-/usr/local/php84/bin/php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "[refresh-data] PHP binary not found: $PHP (set PHP=/path/to/php)" >&2
    exit 1
fi

echo "──────────────────────────────────────────"
echo "  Cyber Trackr daily data refresh"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/8] Refreshing CISA KEV catalog …"
"$PHP" bin/console app:kev:refresh

echo
echo "[2/8] Syncing new STIG bundles from DISA (best-effort) …"
"$PHP" bin/console app:disa:sync-stig || true

echo
echo "[3/8] Syncing new SCAP benchmarks from DISA (best-effort) …"
"$PHP" bin/console app:disa:sync-scap || true

echo
echo "[4/8] Rebuilding stig_toc.json + scap_toc.json + vulns_toc.json …"
"$PHP" bin/console app:stig:rebuild

echo
echo "[5/8] Rebuilding companion-ZIP index for STIG pages …"
"$PHP" bin/console app:companion-zip:rebuild-index

echo
echo "[6/8] Rebuilding bulk-download index (XML/ZIP presence + sizes) …"
"$PHP" bin/console app:bulk-download:rebuild-index

echo
echo "[7/8] Syncing the inverted search index (incremental) …"
"$PHP" bin/console app:search:rebuild

echo
echo "[8/8] Pinging IndexNow about recent STIGs + KEV/vulns surfaces (best-effort) …"
"$PHP" bin/console app:indexnow:ping \
    --recent --within=1 \
    /vulnerabilities \
    /vulnerabilities/kev \
    /vulnerabilities/iavm \
    /sitemap.xml \
    || true

echo
echo "──────────────────────────────────────────"
echo "  Refresh complete."
echo "──────────────────────────────────────────"
