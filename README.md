# Cyber Trackr

> The free compliance library — a fast, browser-friendly home for DISA STIGs, SCAP benchmarks, NIST 800-53 controls, CCIs, and RMF reference data.

**Live:** [cyber.trackr.live](https://cyber.trackr.live)
**Stack:** Symfony 7.4 LTS · PHP 8.2+ · Twig 3 · custom CSS design system (no Bootstrap, no jQuery)
**License:** Proprietary (source-available — see [License](#license))

---

## What this is

`cyber.trackr.live` is a single-developer side project that turns the official DoD compliance corpus into something you can actually browse:

- **1,076 STIG titles** across **3,970 version-instances** (DISA Security Technical Implementation Guides)
- **91 SCAP benchmarks** across **449 version-instances** (XCCDF + OVAL automation content)
- **NIST SP 800-53** revisions **r4** and **r5** controls, fully cross-linked
- The complete **CCI** list (Control Correlation Identifiers, both 2014 and 2024 catalogs)
- A side-by-side **STIG version diff engine**
- A **REST API** that returns the same data as JSON
- A client-side **report generator** that converts CKL/CKLB scan results into shareable reports

It exists because the official sources — cyber.mil, the DISA STIG Viewer, the NIST 800-53 PDF — are heavy, hard to link to, and don't cross-reference each other. This site stitches them together and keeps them in sync.

---

## Why does this exist?

DoD compliance work involves jumping between four authoritative sources:

| Source | Format | Pain |
| --- | --- | --- |
| DISA STIGs | XCCDF XML zips | One zip per STIG, no search across versions, no permalinks |
| DISA SCAP | XCCDF + OVAL XML | Same shape as STIGs but a separate download silo |
| NIST 800-53 | Authoritative PDF + OSCAL | Hard to link to a single control |
| DISA CCI list | One huge XML | Useless without the STIG mappings |

Cyber Trackr unifies them:

- Every STIG, SCAP benchmark, and 800-53 control has its own permalink.
- Cross-references between rules ↔ CCIs ↔ controls are followed automatically.
- The browser does the heavy lifting; the server just renders read-only HTML and JSON over a flat XML/JSON dataset (no database).
- The whole thing is a static-feeling site that loads in a single round trip and reads cleanly on mobile.

---

## Repository layout

```
Cyber.Trackr.Live/                # ← this repo
└── cyber.trackr.live/            # ← the Symfony app (mapped to the live domain)
    ├── bin/                      # Symfony console entrypoint
    ├── config/                   # Symfony bundle + service config
    ├── public/                   # Web root (index.php, css/, js/, fonts/, images/)
    ├── resources/data/           # The compliance corpus (XML + JSON tocs)
    ├── src/                      # Application code
    │   ├── Command/              # Console commands (toc rebuilders)
    │   ├── Controller/           # Route handlers
    │   ├── Service/              # Toc builders, sync status
    │   └── Twig/                 # Custom Twig filters
    ├── templates/                # Twig views
    ├── tests/                    # Test bootstrap (suite is minimal)
    ├── translations/             # i18n scaffolding
    ├── composer.json
    └── TODO.md                   # Archived redesign log
```

The repo root only holds the app directory. The two-level layout exists because the on-disk path `cyber.trackr.live/` maps to the production virtual host, so deploys are a `git pull` and nothing else.

---

## Data sources

All compliance data lives under `cyber.trackr.live/resources/data/`. Sizes as of last sync:

| Path | Source | Size | Notes |
| --- | --- | --- | --- |
| `stig/` | DISA STIG releases (XCCDF XML) | ~1.2 GB | Per-version XML files, named `U_<title>_V<n>R<m>_STIG.xml` |
| `scap/` | DISA SCAP benchmarks (XCCDF + OVAL) | ~444 MB | Same naming pattern as STIGs |
| `cci/U_CCI_List.xml`, `U_CCI_List_2024.xml` | DISA CCI catalog | ~5 MB | Both catalogs kept; new mappings live in 2024 |
| `rmf/800-53v4-controls.xml`, `800-53v5-controls.xml` | NIST SP 800-53 | ~3 MB | Hand-converted from OSCAL |
| `800-53r4.json` | NIST SP 800-53 r4 | ~3.6 MB | JSON form for the API |
| `stig_toc.json`, `scap_toc.json` | Generated indexes | ~2 MB combined | See [Updating the data](#updating-the-data) |
| `sync_status.json` | Manual sync timestamp | <1 KB | Drives the footer's "DISA sync · NIST sync" line |
| `zips/` | Original DISA download zips | ~2.1 GB | Source-of-truth archive; `*Compilation*.zip` is gitignored |

The `*_toc.json` files are pre-computed indexes (title, version, release, release date, severity counts) so the index pages don't have to parse the XML on every request.

---

## Features

### Browseable library

| Route | Page |
| --- | --- |
| `/` | Homepage — hero, recent STIGs (top 100 by release date), trust strip with live counts |
| `/stig` | Full STIG library, filterable, paginated 50/page |
| `/stig/{title}/{version}/{release}` | Single STIG view — overview, all rules, severity mix, CCI cross-refs |
| `/stig/{title}/{v1}/{r1}/{v2}/{r2}` | Side-by-side diff between two versions of the same STIG |
| `/stig/{title}/{version}/{release}/download` | Original DISA zip |
| `/scap` | Full SCAP benchmark library, paginated 50/page |
| `/scap/{title}/{version}/{release}` | Single SCAP view — XCCDF rules, OVAL definitions, severity mix |
| `/scap/{title}/{version}/{release}/download` | Original DISA zip |
| `/cci` | Full CCI list (DataTables, ~3,000 rows) |
| `/rmf/5` | NIST 800-53 r5 controls |
| `/rmf/4` | NIST 800-53 r4 controls |
| `/search/{query}` | Cross-corpus search (STIGs, SCAPs, controls, CCIs, vulns) |
| `/contactus` | Contact form |
| `/report_generator` | Client-side CKL/CKLB → HTML report converter |

### REST API

The API mirrors the browseable library at `/api/...` and returns JSON. No auth, no rate limiting, no headers required.

| Endpoint | Returns |
| --- | --- |
| `GET /api` | Summary of available endpoints + dataset counts |
| `GET /api/stig` | List of all STIG titles + versions |
| `GET /api/stig/tip` | Just the latest version of every STIG (the "tip") |
| `GET /api/stig/{title}/{version}/{release}` | Full rule list for a single STIG |
| `GET /api/stig/{title}/{version}/{release}/{vuln}` | A single vulnerability detail (V-ID) |
| `GET /api/scap` | List of all SCAP benchmarks |
| `GET /api/scap/{title}/{version}/{release}` | Full rule list for a benchmark |
| `GET /api/scap/{title}/{version}/{release}/{vuln}` | Single SCAP vuln |
| `GET /api/rmf/5` | NIST 800-53 r5 control list |
| `GET /api/rmf/5/{con}` | Single 800-53 r5 control (e.g. `AC-2`) |
| `GET /api/rmf/4` | NIST 800-53 r4 control list |
| `GET /api/rmf/4/{con}` | Single 800-53 r4 control |
| `GET /api/cci` | Full CCI list |
| `GET /api/cci/{item}` | Single CCI (e.g. `CCI-000196`) |

### DISA refresh endpoints

Two routes (`/stig/disa_download`, `/scap/disa_download`) scrape the DISA Cyber Exchange catalog API for new releases, fetch the zips, and unpack them into `resources/data/{stig,scap}/`. These are intentionally manual and human-triggered — they're not on a cron.

---

## Console commands

```bash
# Rebuild the STIG index from scratch (re-parses every XML in resources/data/stig/)
bin/console app:stig:rebuild-toc

# Rebuild the SCAP index (includes pre-computed severity counts per benchmark)
bin/console app:scap:rebuild-toc
```

Run these after dropping new DISA files into the data dir. The site auto-detects new XML files on the next request anyway, but a full rebuild is faster than 1,076 incremental parses.

---

## Local development

### Prerequisites

- PHP 8.2 or newer (8.4 is what production runs on)
- Composer 2
- ~5 GB free disk for the data directory
- Symfony CLI is convenient but not required

### Setup

```bash
git clone git@github.com:CyberSecDef/Cyber.Trackr.Live.git
cd Cyber.Trackr.Live/cyber.trackr.live
composer install
```

### Run

```bash
# Symfony CLI
symfony serve

# Or PHP's built-in server
php -S localhost:8000 -t public/
```

Then open <http://localhost:8000>. There is no database to set up, no migrations to run, and no environment variables required for local dev.

### Refresh the index

If you edit a STIG XML by hand (or unpack a new release zip), refresh the indexes:

```bash
bin/console app:stig:rebuild-toc
bin/console app:scap:rebuild-toc
```

### Update the sync timestamp

After a fresh DISA / NIST pull, edit `cyber.trackr.live/resources/data/sync_status.json` so the footer reflects reality:

```json
{
    "disa": "2026-04-29T00:00:00Z",
    "nist": "2026-04-18T00:00:00Z"
}
```

---

## Updating the data

The site is "git is the database" by design. To add or refresh content:

1. **STIGs** — drop new `U_*_STIG.xml` files into `cyber.trackr.live/resources/data/stig/` (or use `/stig/disa_download` to scrape DISA), then `bin/console app:stig:rebuild-toc`.
2. **SCAP** — drop new `U_*_Benchmark.xml` files into `cyber.trackr.live/resources/data/scap/` (or `/scap/disa_download`), then `bin/console app:scap:rebuild-toc`.
3. **CCIs** — replace `cyber.trackr.live/resources/data/cci/U_CCI_List_2024.xml`. No rebuild needed; reads happen on every request.
4. **800-53** — replace the `rmf/800-53v[45]-controls.xml` files. Same — read on demand.
5. **Sync timestamps** — edit `resources/data/sync_status.json`.
6. Commit and push. Production deploy is `git pull`.

> **Note:** any zip with the word "Compilation" in its name is `.gitignore`d. The DISA SRG-STIG sunset compilation bundle is 150 MB and exceeds GitHub's per-file limit; this rule prevents it from being accidentally re-committed.

---

## Tech notes

### Architecture choices

- **Read-only by design.** No database, no user accounts, no admin panel. The dataset is a directory of XML files. State changes happen by editing those files and pushing a commit.
- **No Doctrine, no ORM.** Symfony's framework bundle is loaded; the persistence stack is not.
- **Pre-computed indexes.** `stig_toc.json` and `scap_toc.json` are JSON manifests with title / version / release / release-date / severity counts so the index pages can render without parsing thousands of XML files per request.
- **Custom design system.** No Bootstrap on the user-facing pages (DataTables remains on `/cci` only). Tokens, components, and layout shell live in `public/css/app.css`. Light + dark themes via CSS custom properties + `[data-theme]` attribute on `<html>`.
- **Self-hosted variable fonts.** Fraunces and IBM Plex Sans/Mono ship as latin-subset WOFF2 in `public/fonts/` — no Google Fonts request, no CDN dependency.
- **Theme toggle without FOUC.** A synchronous `<script>` in `<head>` reads `localStorage` and sets `data-theme` before first paint. The toggle button calls `window.app.theme.toggle()`.
- **Atmospheric paper grain.** An inline SVG noise overlay is lazy-applied via `requestAnimationFrame` so the first paint isn't blocked.

### What's in `src/`

| File | Role |
| --- | --- |
| `Controller/HomeController.php` | `/`, `/contactus`, `/report_generator`, `/search` |
| `Controller/StigController.php` | `/stig*` routes including the diff engine |
| `Controller/ScapController.php` | `/scap*` routes including DISA scrape |
| `Controller/RmfController.php` | `/rmf/4`, `/rmf/5` |
| `Controller/CciController.php` | `/cci` |
| `Controller/ApiController.php` | All `/api/*` JSON endpoints |
| `Service/StigTocBuilder.php` | Parses STIG XML → toc entries with severity counts |
| `Service/ScapTocBuilder.php` | Same shape, for SCAP benchmarks |
| `Service/SyncStatus.php` | Reads `sync_status.json`; exposed as Twig global |
| `Twig/AppExtension.php` | Filters: `regex_replace`, `sha1`, `freshness_tag`, `rel_time` |
| `Command/StigRebuildTocCommand.php` | `app:stig:rebuild-toc` |
| `Command/ScapRebuildTocCommand.php` | `app:scap:rebuild-toc` |

### Frontend

| File | Role |
| --- | --- |
| `public/css/app.css` | Full custom design system (~2,700 lines: tokens, components, layout, light/dark themes) |
| `public/js/app.js` | Vanilla JS for theme toggle, hamburger nav, sortable tables, paginated lists, Cmd/Ctrl+K search shortcut |
| `templates/base.html.twig` | Layout shell — sticky header, footer, font preload, theme preflight |
| `templates/macros.html.twig` | Reusable UI primitives: `ident()`, `sev()`, `sev_bar()`, `freshness()` |

---

## Tests

The test suite is intentionally minimal — a `tests/bootstrap.php` and the PHPUnit config. Adding coverage is on the to-do list; the read-only architecture means the bug surface is mostly "did the XML parse correctly", which is best caught by snapshot-style fixtures rather than unit tests of business logic that doesn't really exist.

To run what's there:

```bash
cd cyber.trackr.live
bin/phpunit
```

---

## Author & contact

Built and maintained by **Robert Weber** ([@CyberSecDef](https://github.com/CyberSecDef)).
For corrections, broken links, or new STIG releases that haven't appeared yet, open an issue or use the [contact form](https://cyber.trackr.live/contactus).

---

## License

Code in this repository is **proprietary** (per `composer.json`). The compliance datasets it serves (STIGs, SCAP benchmarks, CCI catalog, NIST 800-53) are public-domain works of the U.S. Government and are redistributed unmodified. Self-hosted typefaces are subject to their own licenses:

- **Fraunces** — SIL Open Font License 1.1
- **IBM Plex Sans / Mono** — SIL Open Font License 1.1

If you want to use any of the application code outside of contributing back to this repository, please reach out first.

---

## Acknowledgments

- **DISA Cyber Exchange** — for publishing the STIG and SCAP corpus openly.
- **NIST** — for the 800-53 catalog.
- **The Symfony team** — for the framework that made the read-only architecture so easy.
- **CTuX, Inspec, OpenRMF, and the broader DoD compliance tooling community** — for proving that the official sources weren't the last word on usability.
