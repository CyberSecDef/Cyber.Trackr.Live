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
#                                       Steps 2-4 are for dev's own site
#                                       and to fail fast on a bad XML
#                                       before shipping. Their outputs
#                                       are NOT shipped: prod's cron pulls
#                                       STIG/SCAP bundles dev never has,
#                                       so dev's indexes would drop those
#                                       from prod. deploy.sh rebuilds them
#                                       on prod from prod's own XML.
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
#                                       on the wire; -h prints sizes
#                                       human-readable; --progress shows
#                                       per-file transfer progress (handy
#                                       for the multi-GB docker tarball);
#                                       --partial keeps a partially-sent
#                                       file so an interrupted transfer
#                                       resumes instead of restarting.
#                                       NOTE: no --delete — this is an
#                                       additive sync, not a mirror, so
#                                       prod-only data (cron-pulled STIG /
#                                       SCAP XML + zips) survives.
#                                       Excluded, because prod builds or
#                                       owns its own copy:
#                                         var/ (caches, logs, share pools)
#                                         resources/data/search/ (index)
#                                         the generated toc / sidecar
#                                           indexes from steps 2-4
#                                         resources/data/sync_status.json
#                                         *.digest.json (STIG digests)
#   8. rsync --delete (code dirs)     — mirror bin/ config/ src/
#                                       templates/ translations/ vendor/
#                                       so files deleted on dev (a removed
#                                       service, an uninstalled package's
#                                       config) don't orphan on prod and
#                                       break the container compile.
#
# After this finishes, SSH to prod and run ./bin/deploy.sh to rebuild
# the toc / sidecar indexes and search index from prod's data, clear
# caches, and ping IndexNow.

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
echo "[1/8] Refreshing CISA KEV catalog …"
"$PHP" bin/console app:kev:refresh

echo
echo "[2/8] Rebuilding stig_toc.json + scap_toc.json + vulns_toc.json …"
"$PHP" bin/console app:stig:rebuild

echo
echo "[3/8] Rebuilding companion-ZIP index for STIG pages …"
"$PHP" bin/console app:companion-zip:rebuild-index

echo
echo "[4/8] Rebuilding bulk-download index (XML/ZIP presence + sizes) …"
"$PHP" bin/console app:bulk-download:rebuild-index

echo
echo "[5/8] Freezing version string …"
"$PHP" bin/console app:version:freeze

echo
echo "[6/8] Freezing changelog from git log …"
"$PHP" bin/console app:changelog:freeze

echo
SRC=/home/rweber/Git/Cyber.Trackr.Live/cyber.trackr.live
DEST=dh_t7zn6y@vps30818.dreamhostps.com:/home/dh_t7zn6y/cyber.trackr.live

echo "[7/8] Rsyncing to prod (additive; prod-owned data excluded) …"
rsync -avz -h --partial --progress \
    --exclude '.env' \
    --exclude '/var/' \
    --exclude '/resources/data/search/' \
    --exclude '/resources/data/stig_toc.json' \
    --exclude '/resources/data/scap_toc.json' \
    --exclude '/resources/data/vulns_toc.json' \
    --exclude '/resources/data/companion_zip_index.json' \
    --exclude '/resources/data/bulk_download_index.json' \
    --exclude '/resources/data/sync_status.json' \
    --exclude '*.digest.json' \
    "$SRC/" "$DEST/"

echo
echo "[8/8] Mirroring code dirs to prod (prunes files deleted on dev) …"
for dir in bin config src templates translations vendor; do
    rsync -az -h --delete --exclude '.env' "$SRC/$dir/" "$DEST/$dir/"
done

echo
echo "──────────────────────────────────────────"
echo "  Ship complete."
echo
echo "  Next step — run on prod:"
echo "    ssh dh_t7zn6y@vps30818.dreamhostps.com \\"
echo "      'cd /home/dh_t7zn6y/cyber.trackr.live && ./bin/deploy.sh'"
echo "──────────────────────────────────────────"
