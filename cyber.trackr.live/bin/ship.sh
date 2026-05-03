#!/usr/bin/env bash
# Dev-side ship script for Cyber Trackr.
#
# Run from dev (this machine), not from prod:
#
#   ./bin/ship.sh
#
# Order:
#   1. app:kev:refresh      — pull the latest CISA Known Exploited
#                             Vulnerabilities catalog into resources/data/kev/
#                             so the rsync ships fresh KEV data. Network-
#                             dependent; runs first so we fail fast if
#                             CISA's feed is unreachable.
#   2. app:vulns:rebuild-toc — re-scan the STIG + SCAP corpus for IAVM/
#                             CTO/CVE refs into resources/data/vulns_toc.json.
#                             Cheap (~30s); harmless to run every ship
#                             since the inputs rarely change between ships
#                             and the JSON output is tiny.
#   3. app:version:freeze   — bake the version string into /VERSION so
#                             prod doesn't try to compute it from .git
#                             (which doesn't survive the rsync).
#   4. app:changelog:freeze — snapshot the git log into
#                             resources/data/changelog.json so the
#                             /changelog page works on prod.
#   5. rsync                — push everything except .env to the prod
#                             host. -a preserves perms / mtimes;
#                             -v prints each transferred file;
#                             -z compresses on the wire.
#
# After this finishes, SSH to prod and run ./bin/deploy.sh to clear
# caches, rebuild the search index, and ping IndexNow.

set -euo pipefail
cd "$(dirname "$0")/.."

echo "──────────────────────────────────────────"
echo "  Cyber Trackr ship (dev → prod)"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/5] Refreshing CISA KEV catalog …"
php bin/console app:kev:refresh

echo
echo "[2/5] Rebuilding vulns_toc.json from the STIG/SCAP corpus …"
php bin/console app:vulns:rebuild-toc

echo
echo "[3/5] Freezing version string …"
php bin/console app:version:freeze

echo
echo "[4/5] Freezing changelog from git log …"
php bin/console app:changelog:freeze

echo
echo "[5/5] Rsyncing to prod (--exclude .env) …"
rsync -avz \
    --exclude '.env' \
    /home/rweber/Git/Cyber.Trackr.Live/cyber.trackr.live/ \
    dh_t7zn6y@vps30818.dreamhostps.com:/home/dh_t7zn6y/cyber.trackr.live/

echo
echo "──────────────────────────────────────────"
echo "  Ship complete."
echo
echo "  Next step — run on prod:"
echo "    ssh dh_t7zn6y@vps30818.dreamhostps.com \\"
echo "      'cd /home/dh_t7zn6y/cyber.trackr.live && ./bin/deploy.sh'"
echo "──────────────────────────────────────────"
