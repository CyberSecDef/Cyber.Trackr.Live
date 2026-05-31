# Air-gapped Docker image

A self-contained image of the site — nginx + PHP-FPM with **all of
`resources/data/` baked in** — for running in standalone, offline / air-gapped
environments. The container makes **no network calls**: every page reads local
files, so once built it needs no internet at all.

Cut a fresh image monthly (or whenever you want a new data snapshot).

## What's in the image

- PHP 8.4-FPM (extensions: `zip`, `intl`, `gd`, `opcache`) + nginx, run together
  under supervisor. One container, one port (`80`).
- Production Composer dependencies, prod cache pre-warmed at build time.
- A point-in-time copy of `resources/data/` (STIGs, SCAP, CCIs, RMF, KEV, the
  search index, original DISA ZIPs — ~4 GB).

No database, no external services. The only writable path is `/app/var`
(prod cache + CSRF session files), mounted as a volume in `docker-compose.yml`.

## Build (on a network-connected host)

```bash
# Tag defaults to the current YYYY-MM. Add --refresh to pull the latest
# DISA/CISA data first; omit it to package whatever is already on disk.
bin/build-image.sh --refresh            # -> cyber-trackr:2026-05 + cyber-trackr-2026-05.tar.gz
bin/build-image.sh 2026-06              # specific tag, no data refresh
```

The script freezes the version + changelog, runs `docker build`, and exports a
gzipped tarball (`dist/cyber-trackr-<TAG>.tar.gz`) ready to carry across the air
gap. The `dist/` directory is gitignored.

## Deploy (inside the air-gapped environment)

```bash
gunzip -c dist/cyber-trackr-2026-05.tar.gz | docker load
docker compose up -d           # serves on http://localhost:8080
#   or, without compose:
docker run -d -p 8080:80 -v cyber_var:/app/var cyber-trackr:2026-05
```

## Configuration

| Env var      | Default            | Notes                                                        |
|--------------|--------------------|--------------------------------------------------------------|
| `APP_ENV`    | `prod`             | Leave as-is.                                                 |
| `APP_SECRET` | random per start   | Pin a value to keep CSRF tokens valid across restarts.       |

Change the published port by editing the `ports:` mapping in
`docker-compose.yml` (host side only; the container always listens on 80).

## Monthly refresh, in one line

```bash
bin/build-image.sh --refresh && \
  scp dist/cyber-trackr-$(date +%Y-%m).tar.gz <transfer-host>:
```

Then `docker load` + `docker compose up -d` on the target. Old images can be
pruned with `docker image rm cyber-trackr:<old-tag>`.
