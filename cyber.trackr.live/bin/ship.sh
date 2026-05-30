#!/usr/bin/env bash
# Dev-side ship script for Cyber Trackr.
#
# Run from dev (this machine), not from prod:
#
#   ./bin/ship.sh
#
# Order:
#   1. app:kev:refresh                — pull the latest CISA Known
#                                       Exploited Vulnerabilities
#                                       catalog so the rsync ships fresh
#                                       KEV data. Network-dependent;
#                                       runs first so we fail fast if
#                                       CISA's feed is unreachable.
#   2. app:stig:rebuild               — rebuild stig_toc.json,
#                                       scap_toc.json, and vulns_toc.json
#                                       from current XML. Covers anything
#                                       a manual `app:disa:sync-*` (or a
#                                       hand-drop into resources/data/)
#                                       added since the last ship.
#   3. app:companion-zip:rebuild-index — refresh the XML→ZIP map so the
#                                       Supporting documents section on
#                                       STIG view pages reflects the
#                                       current archive.
#   4. app:bulk-download:rebuild-index — refresh per-row XML/ZIP presence
#                                       + sizes that drive /stig/bulk.
#                                       Depends on step 2.
#   5. app:version:freeze             — bake the version string into
#                                       /VERSION so prod doesn't try to
#                                       compute it from .git (which
#                                       doesn't survive the rsync).
#   6. app:changelog:freeze           — snapshot the git log into
#                                       resources/data/changelog.json so
#                                       the /changelog page works on
#                                       prod.
#   7. rsync                          — push everything except .env to
#                                       the prod host. -a preserves
#                                       perms / mtimes; -v prints each
#                                       transferred file; -z compresses
#                                       on the wire.
#
# After this finishes, SSH to prod and run ./bin/deploy.sh to clear
# caches, rebuild the search index, and ping IndexNow.

set -euo pipefail
cd "$(dirname "$0")/.."

# PHP binary to run the console with. This script runs on dev, where the
# default `php` is current; override with `PHP=/path/to/php ./bin/ship.sh`
# if your dev php is too old for composer.json.
PHP="${PHP:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "[ship] PHP binary not found: $PHP (set PHP=/path/to/php)" >&2
    exit 1
fi

echo "──────────────────────────────────────────"
echo "  Cyber Trackr ship (dev → prod)"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

echo
echo "[1/7] Refreshing CISA KEV catalog …"
"$PHP" bin/console app:kev:refresh

echo
echo "[2/7] Rebuilding stig_toc.json + scap_toc.json + vulns_toc.json …"
"$PHP" bin/console app:stig:rebuild

echo
echo "[3/7] Rebuilding companion-ZIP index for STIG pages …"
"$PHP" bin/console app:companion-zip:rebuild-index

echo
echo "[4/7] Rebuilding bulk-download index (XML/ZIP presence + sizes) …"
"$PHP" bin/console app:bulk-download:rebuild-index

echo
echo "[5/7] Freezing version string …"
"$PHP" bin/console app:version:freeze

echo
echo "[6/7] Freezing changelog from git log …"
"$PHP" bin/console app:changelog:freeze

echo
echo "[7/7] Rsyncing to prod (--exclude .env) …"
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
