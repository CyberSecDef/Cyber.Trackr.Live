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
#   4. app:vulns:rebuild-toc          — re-scans STIG + SCAP XML for
#                                       IAVM/CTO/CVE refs into
#                                       vulns_toc.json. Cheap.
#   5. app:companion-zip:rebuild-index — re-scans resources/data/zips/
#                                       and rebuilds the XML→ZIP map
#                                       used to surface DISA companion
#                                       PDFs on each STIG page. ~5s
#                                       for ~1200 ZIPs. Idempotent.
#   6. app:bulk-download:rebuild-index — stat()s every STIG XML and
#                                       companion ZIP to build the
#                                       presence/size sidecar used by
#                                       /stig/bulk. Depends on step 5.
#   7. app:indexnow:ping              — best-effort nudge to Bing/
#                                       Yandex/etc. that the vulns
#                                       landing pages + sitemap
#                                       changed. Pings the index
#                                       surfaces only, not every CVE
#                                       detail URL — search engines
#                                       re-crawl those from the
#                                       sitemap. Wrapped in `|| true`
#                                       so a transient IndexNow
#                                       failure can't fail the cron
#                                       run and skip tomorrow's email.
#
# Cron runs with a minimal PATH, so we set one explicitly and use
# absolute cwd via $(dirname "$0")/.. — same pattern as ship.sh /
# deploy.sh. Errors surface via the log file referenced above.

set -euo pipefail
export PATH=/usr/local/bin:/usr/bin:/bin
cd "$(dirname "$0")/.."

echo "──────────────────────────────────────────"
echo "  Cyber Trackr daily data refresh"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/7] Refreshing CISA KEV catalog …"
php bin/console app:kev:refresh

echo
echo "[2/7] Syncing new STIG bundles from DISA (best-effort) …"
php bin/console app:disa:sync-stig || true

echo
echo "[3/7] Syncing new SCAP benchmarks from DISA (best-effort) …"
php bin/console app:disa:sync-scap || true

echo
echo "[4/7] Rebuilding vulns_toc.json from the STIG/SCAP corpus …"
php bin/console app:vulns:rebuild-toc

echo
echo "[5/7] Rebuilding companion-ZIP index for STIG pages …"
php bin/console app:companion-zip:rebuild-index

echo
echo "[6/7] Rebuilding bulk-download index (XML/ZIP presence + sizes) …"
php bin/console app:bulk-download:rebuild-index

echo
echo "[7/7] Pinging IndexNow about KEV/vulns surfaces (best-effort) …"
php bin/console app:indexnow:ping \
    /vulnerabilities \
    /vulnerabilities/kev \
    /vulnerabilities/iavm \
    /sitemap.xml \
    || true

echo
echo "──────────────────────────────────────────"
echo "  Refresh complete."
echo "──────────────────────────────────────────"
