#!/usr/bin/env bash
# Build a self-contained, air-gap-ready image with all current data baked in,
# then export it as a gzipped tarball for transport into the air gap.
#
#   bin/build-image.sh [TAG] [--refresh]
#
#   TAG        Image tag; defaults to the current year-month (e.g. 2026-05).
#   --refresh  Run the full network data sync (bin/refresh-data.sh) first so
#              the image carries the latest DISA/CISA data. Omit it to package
#              whatever is already on disk.
#
# Run this on a network-connected build host. The output tarball is then
# carried into the air-gapped environment and `docker load`-ed (see DOCKER.md).
set -euo pipefail

cd "$(dirname "$0")/.."

TAG=""
REFRESH=0
for arg in "$@"; do
    case "$arg" in
        --refresh) REFRESH=1 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *)  TAG="$arg" ;;
    esac
done
TAG="${TAG:-$(date +%Y-%m)}"

IMAGE="cyber-trackr:${TAG}"
OUT="dist/cyber-trackr-${TAG}.tar.gz"
mkdir -p dist

if [ "$REFRESH" -eq 1 ]; then
    echo ">> Refreshing data from DISA/CISA (network required)…"
    bin/refresh-data.sh
fi

# Freeze the bits that normally derive from git/network so they render offline.
echo ">> Freezing version + changelog…"
php bin/console app:version:freeze
php bin/console app:changelog:freeze

echo ">> Building ${IMAGE} (bundles ~4 GB of data — this takes a while)…"
docker build -f Dockerfile -t "$IMAGE" -t "cyber-trackr:latest" .

echo ">> Exporting ${OUT}…"
docker save "$IMAGE" "cyber-trackr:latest" | gzip > "$OUT"

cat <<EOF

Done.  $(du -h "$OUT" | cut -f1)  ->  ${OUT}

Carry it into the air-gapped host and run:
    gunzip -c ${OUT} | docker load
    docker compose up -d            # or: docker run -d -p 8080:80 ${IMAGE}
    # browse http://localhost:8080
EOF
