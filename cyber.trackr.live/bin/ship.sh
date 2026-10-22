#!/usr/bin/env bash
# Dev-side ship script for Cyber Trackr.
#
# Run from dev (this machine), not from prod:
#
#   ./bin/ship.sh             ship
#   ./bin/ship.sh --dry-run   skip steps 1-6; show what steps 7-8 would send
#   ./bin/ship.sh --force     ship even with uncommitted changes in the app
#
# Refuses to ship when tracked files under the app dir have uncommitted
# changes, so only committed code reaches prod (review S11).
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
#   7. rsync                          — push an ALLOWLIST of top-level
#                                       paths (bin config dist public
#                                       resources src templates
#                                       translations vendor, composer.*,
#                                       symfony.lock, VERSION, .env.prod)
#                                       to the prod host. Anything else at
#                                       the root (.env.local, .env.dev,
#                                       tests/, node_modules/, tool
#                                       configs) stays on dev (review
#                                       S11). -a preserves
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
#                                       break the container compile. A
#                                       prod-only Symfony secrets decrypt
#                                       key in config/ is excluded, so the
#                                       mirror can never delete it.
#
# After this finishes, SSH to prod and run ./bin/deploy.sh to rebuild
# the toc / sidecar indexes and search index from prod's data, clear
# caches, and ping IndexNow.

set -euo pipefail
cd "$(dirname "$0")/.."
SRC="$(pwd)"
DEST=dh_t7zn6y@vps30818.dreamhostps.com:/home/dh_t7zn6y/cyber.trackr.live

DRY_RUN=0
FORCE=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --force)   FORCE=1 ;;
        -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
        *) echo "[ship] unknown option: $arg (try --help)" >&2; exit 2 ;;
    esac
done

# Only committed code goes to prod. Untracked files don't count (VERSION,
# the KEV catalog and other generated data are untracked by design).
DIRTY="$(git -C "$SRC" status --porcelain --untracked-files=no -- . || true)"
if [ -n "$DIRTY" ]; then
    if [ "$DRY_RUN" = 1 ] || [ "$FORCE" = 1 ]; then
        echo "[ship] WARNING: uncommitted changes in the app (shipping anyway):" >&2
        echo "$DIRTY" >&2
    else
        echo "[ship] REFUSING: uncommitted changes in the app. Commit them, or re-run with --force:" >&2
        echo "$DIRTY" >&2
        exit 1
    fi
fi

# PHP binary to run the console with. This script runs on dev, where the
# default `php` is current; override with `PHP=/path/to/php ./bin/ship.sh`
# if your dev php is too old for composer.json.
PHP="${PHP:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "[ship] PHP binary not found: $PHP (set PHP=/path/to/php)" >&2
    exit 1
fi

echo "──────────────────────────────────────────"
echo "  Cyber Trackr ship (dev → prod)$([ "$DRY_RUN" = 1 ] && echo '  [DRY RUN]')"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

if [ "$DRY_RUN" = 1 ]; then
    echo
    echo "[1-6] Skipped in --dry-run (KEV refresh, index rebuilds, version/changelog freeze)."
else

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
fi

# --dry-run: list what would change instead of sending it.
RSYNC_MODE=(-avz -h --partial --progress)
MIRROR_MODE=(-az -h)
if [ "$DRY_RUN" = 1 ]; then
    RSYNC_MODE=(-azn -h --itemize-changes)
    MIRROR_MODE=(-azn -h --itemize-changes)
fi

echo
echo "[7/8] Rsyncing to prod (additive; allowlisted paths; prod-owned data excluded) …"
# Filter order matters (first match wins): prod-owned data excludes, then
# the allowlist of top-level paths, then exclude everything else at the root.
rsync "${RSYNC_MODE[@]}" \
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
    --exclude '/public/.dh-diag/' \
    --include '/bin/' --include '/config/' --include '/dist/' --include '/public/' \
    --include '/resources/' --include '/src/' --include '/templates/' \
    --include '/translations/' --include '/vendor/' \
    --include '/composer.json' --include '/composer.lock' --include '/symfony.lock' \
    --include '/VERSION' --include '/.env.prod' \
    --exclude '/*' \
    "$SRC/" "$DEST/"

echo
echo "[8/8] Mirroring code dirs to prod (prunes files deleted on dev) …"
for dir in bin config src templates translations vendor; do
    rsync "${MIRROR_MODE[@]}" --delete --exclude '.env' \
        --exclude '/secrets/prod/prod.decrypt.private.php' \
        "$SRC/$dir/" "$DEST/$dir/"
done

echo
if [ "$DRY_RUN" = 1 ]; then
    echo
    echo "Dry run complete — nothing was sent."
    exit 0
fi

echo "──────────────────────────────────────────"
echo "  Ship complete."
echo
echo "  Next step — run on prod:"
echo "    ssh dh_t7zn6y@vps30818.dreamhostps.com \\"
echo "      'cd /home/dh_t7zn6y/cyber.trackr.live && ./bin/deploy.sh'"
echo "──────────────────────────────────────────"
