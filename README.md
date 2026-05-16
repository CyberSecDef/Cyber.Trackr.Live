# Cyber Trackr

> The free compliance library — a fast, browser-friendly home for DISA STIGs, SCAP benchmarks, NIST 800-53 controls, CCIs, RMF reference data, and a 20-family RMF plan generator.

**Live:** [cyber.trackr.live](https://cyber.trackr.live)
**Stack:** Symfony 7.4 LTS · PHP 8.2+ · Twig 3 · custom CSS design system · jQuery 3 + Bootstrap 5 + DataTables for interactive surfaces
**License:** [GNU AGPL-3.0-or-later](LICENSE) — see [License](#license) and [`NOTICE.md`](NOTICE.md) for the full attribution

---

## What this is

`cyber.trackr.live` is a single-developer side project that turns the official DoD compliance corpus into something you can actually browse:

- **1,076 STIG titles** across **3,970 version-instances** (DISA Security Technical Implementation Guides)
- **91 SCAP benchmarks** across hundreds of version-instances (XCCDF + OVAL automation content)
- **NIST SP 800-53** revisions **r4** and **r5**, fully cross-linked, with an `r4 → r5` mapping page that harvests withdrawal targets from r5's own catalog
- **14 control baselines** loaded from OSCAL profiles (NIST L/M/H/Privacy + FedRAMP L/M/H/LI-SaaS + CNSSI 1253 L/M/H/Privacy/Classified/Space)
- The complete **CCI** list (Control Correlation Identifiers, both 2014 and 2024 catalogs)
- A side-by-side **STIG version diff engine** plus a per-version **Digest of Updates** with an Atom feed
- A **20-family RMF Plan Generator** (CM, AC, AU, IA, IR, CP, SI, CA, SC, RA, SA, PE, PS, MA, MP, AT, PL, SR, PT, PM) that produces assessable Word documents with ODV substitution and enhancement-tailoring traceability
- A **CKL / CKLB viewer** that runs entirely in the browser, edits any field, applies SCAP scan results, and re-exports CKLB
- A **baseline coverage heat map** (20 families × 4 NIST baselines) for scoping
- A **REST API** that returns the same data as JSON

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
- The browser does the heavy lifting for editor-style tools (CKL viewer, plan wizards); the server just renders read-only HTML and JSON over a flat XML/JSON dataset (no database, no user accounts).
- The whole thing is a static-feeling site that loads in a single round trip and reads cleanly on mobile.

---

## Repository layout

```
Cyber.Trackr.Live/                # ← this repo
└── cyber.trackr.live/            # ← the Symfony app (mapped to the live domain)
    ├── bin/                      # Symfony console entrypoint
    ├── config/                   # Symfony bundle + service config
    ├── public/                   # Web root (index.php, css/, js/, fonts/, images/, og/)
    ├── resources/data/           # The compliance corpus (XML + JSON tocs + plan schemas)
    ├── src/                      # Application code
    │   ├── Command/              # Console commands (toc + index + version + deploy)
    │   ├── Controller/           # Route handlers
    │   ├── Service/              # Toc builders, overlay loader, mappers, plan + search engines
    │   └── Twig/                 # Custom Twig filters
    ├── templates/                # Twig views
    ├── tests/                    # Test bootstrap (suite is minimal)
    ├── translations/             # i18n scaffolding
    ├── composer.json
    └── TODO.md                   # Active backlog + deferred items
```

The repo root only holds the app directory. The two-level layout exists because the on-disk path `cyber.trackr.live/` maps to the production virtual host, so deploys are a `git pull` and nothing else.

---

## Data sources

All compliance data lives under `cyber.trackr.live/resources/data/`. Sizes as of last sync:

| Path | Source | Size | Notes |
| --- | --- | --- | --- |
| `stig/` | DISA STIG releases (XCCDF XML) | ~1.2 GB | Per-version XML files, named `U_<title>_V<n>R<m>_STIG.xml`. Lazy `.digest.json` files live alongside (gitignored, regenerated from source). |
| `scap/` | DISA SCAP benchmarks (XCCDF + OVAL) | ~444 MB | Same naming pattern as STIGs |
| `cci/U_CCI_List.xml`, `U_CCI_List_2024.xml` | DISA CCI catalog | ~5 MB | Both catalogs kept; new mappings live in 2024 |
| `rmf/800-53v4-controls.xml`, `800-53v5-controls.xml` | NIST SP 800-53 | ~3 MB | Hand-converted from OSCAL. r5 carries `<status>withdrawn</status>` + `<incorporated-into>` for retired controls — harvested by the r4 → r5 mapping page. |
| `rmf/r4-to-r5-mapping.json` | Curated overrides | <2 KB | Optional rationale text on top of the mechanical r4 ↔ r5 inference; empty by default. |
| `overlays/*_profile.json` | OSCAL Profile JSON | ~3 MB | 14 baseline profiles (NIST + FedRAMP + CNSSI 1253). Drop a new one in and it appears in chips, plan wizards, and the baseline heat map automatically. |
| `plans/*.json` | Plan-generator schemas | ~500 KB | One file per RMF family (20 total) plus `_shared.json`. Schema-driven — adding a family is a JSON file, not a code change. |
| `800-53r4.json` | NIST SP 800-53 r4 | ~3.6 MB | JSON form for the API |
| `stig_toc.json`, `scap_toc.json` | Generated indexes | ~2 MB combined | See [Updating the data](#updating-the-data) |
| `vulns_toc.json` | IAVM/CTO/CVE refs harvested from STIG + SCAP XML | <1 MB | Generated by `app:vulns:rebuild-toc`. Feeds `/vulnerabilities/iavm`. |
| `kev/known_exploited_vulnerabilities.json` | CISA KEV catalog mirror | ~1.5 MB | Generated by `app:kev:refresh`. Feeds `/vulnerabilities/kev`. |
| `companion_zip_index.json` | XCCDF-XML → companion-ZIP map | ~150 KB | Built by `app:companion-zip:rebuild-index`. Resolves DISA's inconsistent ZIP filenames (e.g. `U_ASD_V6R4_STIG.zip`) back to the canonical STIG title. Feeds the **Supporting documents** section on STIG view pages. |
| `bulk_download_index.json` | Per-version XML/ZIP presence + size | ~1.1 MB | Built by `app:bulk-download:rebuild-index`. Lets the bulk-download table render without `stat()`ing thousands of files per request and powers the 500-MB chunk planner. |
| `search/` | Inverted search index | up to 100+ MB | Sharded JSON. Generated by `app:search:rebuild`. Gitignored. |
| `sync_status.json` | Manual sync timestamp | <1 KB | Drives the footer's "DISA sync · NIST sync" line |
| `zips/` | Original DISA download zips | ~2.1 GB | Source-of-truth archive; `*Compilation*.zip` is gitignored |

The `*_toc.json` files are pre-computed indexes (title, version, release, release date, severity counts) so the index pages don't have to parse the XML on every request.

---

## Features

### Browseable library

| Route | Page |
| --- | --- |
| `/` | Homepage — hero, recent STIGs (top 100 by release date), trust strip with live counts, common-workflows chip strip |
| `/stig` | Full STIG library, filterable, paginated 50/page — with a collapsed **Bulk download** panel for grabbing any combination of XML + companion ZIP across every version |
| `/stig/bulk` | Standalone bulk-download view — virtual-scrolled table of all 3,970 versions with independent XML / ZIP checkbox columns, streams ≤500 MB chunks sequentially |
| `/stig/{title}/{version}/{release}` | Single STIG view — overview, all rules, severity mix, CCI cross-refs, **Supporting documents** (DISA companion PDFs when archived), **Digest of Updates** vs the previous version |
| `/stig/{title}/{v1}/{r1}/{v2}/{r2}` | Side-by-side diff between two versions of the same STIG |
| `/stig/{title}/{version}/{release}/download` | Original DISA STIG XCCDF XML |
| `/stig/{title}/{version}/{release}/companion.zip` | DISA companion bundle (overview, revision history, readme PDFs) — streamed from the `zips/` archive when present |
| `/stig/{title}/{version}/{release}/companion.pdf?file=…` | Individual PDF entry from the companion bundle |
| `/stig/feed.atom` | Atom feed of the 25 most recent STIG versions with full digests (added/removed/severity-changed/content-changed rules) |
| `/scap` | Full SCAP benchmark library, paginated 50/page |
| `/scap/{title}/{version}/{release}` | Single SCAP view — XCCDF rules, OVAL definitions, severity mix |
| `/scap/{title}/{version}/{release}/download` | Original DISA zip |
| `/cci` | Full CCI list (DataTables, ~3,000 rows) |
| `/rmf/5` | NIST 800-53 r5 controls with baseline-membership chips (NIST + FedRAMP + CNSSI 1253) |
| `/rmf/4` | NIST 800-53 r4 controls |
| `/rmf/4-to-5` | Side-by-side r4 → r5 mapping with withdrawal targets harvested from r5's own catalog metadata |
| `/baselines` | 20×4 coverage heat map — which families contribute heaviest at NIST Low / Moderate / High / Privacy |
| `/vulnerabilities` | Vulnerabilities landing — KEV summary stats, IAVM/CTO/CVE harvest stats, DoD-users blurb, links into the catalogs |
| `/vulnerabilities/kev` | Full CISA KEV catalog (DataTable, ~1,500 entries) |
| `/vulnerabilities/kev/{cve}` | Single KEV entry — CISA description, BOD 22-01 due date, related STIG/SCAP rules, related 800-53 controls |
| `/vulnerabilities/iavm` | IAVMs / CTOs harvested from the public STIG/SCAP corpus |
| `/vulnerabilities/iavm/{id}` | Single bulletin with rule backlinks and co-occurring CVEs |
| `/vulnerabilities/cve/{cve}` | Unifying CVE view — works whether or not the CVE is in KEV; joins KEV info + STIG/SCAP refs |
| `/search/{query}` | Cross-corpus search (STIGs, SCAPs, controls, CCIs, vulns) |
| `/plans` | Plan Generator landing page — 20 family cards, PT and PM badged as privacy-baseline plans |
| `/plans/{family}` | Per-family wizard — system metadata, baseline picker (grouped by source with friendly labels), structured family questions, per-control cards with ODV substitution + enhancement disposition pickers |
| `/plans/{family}/preview` | HTML preview of the rendered plan (POST) |
| `/plans/{family}/download` | DOCX export via PHPWord (POST) |
| `/ckl-viewer` | Browser-only CKL/CKLB editor with editable asset header, severity stat cards, status donut + severity-status bar charts (hand-rolled SVG), per-rule editor, SCAP XCCDF overlay, CKLB export |
| `/report_generator` | Legacy CKL/CKLB → POAM/RAR converter (predates the dedicated CKL viewer) |
| `/contactus`, `/mission` | Contact + about |

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
| `GET /api/vulns` | Vulnerabilities root — KEV summary, corpus stats, related-control map, endpoint index |
| `GET /api/vulns/kev` | Full CISA KEV catalog (mirrored locally) |
| `GET /api/vulns/kev/{cve}` | Single KEV entry + corpus backlinks |
| `GET /api/vulns/iavm` | IAVMs and CTOs from the STIG/SCAP corpus |
| `GET /api/vulns/iavm/{id}` | Single bulletin with rule backlinks |
| `GET /api/vulns/cve/{cve}` | Unified CVE view (KEV record if any + corpus refs) |
| `GET /plans/{family}/schema.json` | Per-family plan schema (consumed by the wizard JS but readable directly) |

### DISA refresh endpoints

Two routes (`/stig/disa_download`, `/scap/disa_download`) scrape the DISA Cyber Exchange catalog API for new releases, fetch the zips, and unpack them into `resources/data/{stig,scap}/`. These are intentionally manual and human-triggered — they're not on a cron.

### Plan Generator at a glance

The plan generator sits at the assessability floor — produced plans aren't just structurally compliant, they prompt the assessor-relevant content the user needs to author:

- **Schema-driven.** Every family is a JSON file under `resources/data/plans/`. Adding a new one is data, not code.
- **ODV substitution.** Catalog statement text like `[Assignment: organization-defined frequency]` is parsed out, surfaced as inputs, and substituted at render time. Position-indexed IDs survive catalog updates.
- **Enhancement tailoring.** Every enhancement in scope of a selected base control gets a Selected / Inherited / Tailored Out picker with rationale. Out-of-baseline enhancements pre-default to Tailored Out.
- **Per-control guidance.** Schemas can declare prompts (checklist items), evidence suggestions (one-click chips that pre-populate the evidence list), and structured extra fields per high-impact control.
- **BYOJSON persistence.** Drafts autosave to `localStorage`, and the wizard supports saving/loading them as JSON files. No server-side state.
- **Word + JSON output.** Final plans render as `.docx` via PHPWord with a TOC, glossary, and acronyms appendix.

### CKL Viewer at a glance

`/ckl-viewer` is a polished browser-only checklist editor:

- Drop a `.ckl` (XML) or `.cklb` (JSON) — both parse into a unified internal model.
- Hand-rolled SVG charts for status distribution (donut with `% compliant` center label) and severity × status (grouped bars).
- Editable host/asset metadata + per-rule editor (status, finding details, comments, severity override + justification).
- Drop a SCAP XCCDF result on the loaded checklist to overlay scan statuses (pass → NotAFinding, fail → Open, etc.) with a `[SCAP <result> — date]` stamp appended to the finding details.
- Re-export as CKLB JSON that DISA STIG Viewer 3.x reads natively.

---

## Console commands

```bash
# Rebuild the STIG index from scratch (re-parses every XML in resources/data/stig/)
bin/console app:stig:rebuild-toc

# Rebuild the SCAP index (includes pre-computed severity counts per benchmark)
bin/console app:scap:rebuild-toc

# Re-scan the STIG + SCAP corpus for IAVM/CTO/CVE references
# (output: resources/data/vulns_toc.json — feeds /vulnerabilities)
bin/console app:vulns:rebuild-toc

# Pull the latest CISA Known Exploited Vulnerabilities catalog into
# resources/data/kev/known_exploited_vulnerabilities.json
bin/console app:kev:refresh

# Build the XCCDF-XML → companion-ZIP map (resolves DISA's inconsistent
# zip filenames) used by the "Supporting documents" section on each STIG.
bin/console app:companion-zip:rebuild-index

# Build the per-version XML/ZIP presence + size index that drives the
# /stig/bulk table and the chunk planner.
bin/console app:bulk-download:rebuild-index

# Meta: run app:stig:rebuild-toc + app:scap:rebuild-toc + app:vulns:rebuild-toc
# in one shot (use after a bulk DISA refresh)
bin/console app:stig:rebuild

# Rebuild the inverted search index (sharded JSON under resources/data/search/)
bin/console app:search:rebuild

# Freeze the version string into a /VERSION file for deploys
bin/console app:version:freeze

# Pull + rebuild + restart in one step (used by the production deploy hook)
bin/console app:deploy
```

Run the toc rebuilds after dropping new DISA files into the data dir. The site auto-detects new XML files on the next request anyway, but a full rebuild is faster than thousands of incremental parses. `app:stig:rebuild` is the convenience wrapper when you've just done a bulk drop and want every dependent index refreshed in one command.

---

## Local development

### Prerequisites

- PHP 8.2 or newer (8.4 is what production runs on)
- Composer 2
- ImageMagick (for regenerating the OG-image PNGs from `public/og/src/*.svg`)
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
# Quick path: one command does stig + scap + vulns toc rebuilds
bin/console app:stig:rebuild

# Or run each individually
bin/console app:stig:rebuild-toc
bin/console app:scap:rebuild-toc
bin/console app:vulns:rebuild-toc
bin/console app:search:rebuild

# CISA KEV is independent (network call)
bin/console app:kev:refresh
```

### Update the sync timestamp

After a fresh DISA / NIST pull, edit `cyber.trackr.live/resources/data/sync_status.json` so the footer reflects reality:

```json
{
    "disa": "2026-04-29T00:00:00Z",
    "nist": "2026-04-18T00:00:00Z"
}
```

### Regenerate OG images

Social-share images at `/og/<surface>.png` are rendered from SVG sources at `public/og/src/`. To update visuals or copy:

```bash
bash cyber.trackr.live/public/og/src/build.sh
```

---

## Updating the data

The site is "git is the database" by design. To add or refresh content:

1. **STIGs** — drop new `U_*_STIG.xml` files into `cyber.trackr.live/resources/data/stig/` (or use `/stig/disa_download` to scrape DISA), then `bin/console app:stig:rebuild-toc`. After a bulk drop, prefer `bin/console app:stig:rebuild` so the SCAP toc and `vulns_toc.json` refresh too.
2. **SCAP** — drop new `U_*_Benchmark.xml` files into `cyber.trackr.live/resources/data/scap/` (or `/scap/disa_download`), then `bin/console app:scap:rebuild-toc` (or `app:stig:rebuild` for the bundled refresh).
3. **CCIs** — replace `cyber.trackr.live/resources/data/cci/U_CCI_List_2024.xml`. No rebuild needed; reads happen on every request.
4. **800-53** — replace the `rmf/800-53v[45]-controls.xml` files. Same — read on demand.
5. **Baselines** — drop a new `*_profile.json` into `resources/data/overlays/`. The chip strip on `/rmf/5`, the wizard dropdown on every plan, and the baseline heat map all pick it up automatically.
6. **Plan families** — drop a new `<family>.json` into `resources/data/plans/`. The plan-generator landing page and wizard machinery handle it.
7. **CISA KEV + derived indexes** — both `bin/ship.sh` (deploy-time) and the daily `bin/refresh-data.sh` cron refresh the KEV catalog (`app:kev:refresh`), the IAVM/CTO/CVE harvest (`app:vulns:rebuild-toc`), the XML→ZIP companion map (`app:companion-zip:rebuild-index`), and the bulk-download presence/size index (`app:bulk-download:rebuild-index`). Run any of them individually with `bin/console <name>` for a one-off rebuild.
8. **Sync timestamps** — edit `resources/data/sync_status.json`.
9. Commit and push. Production deploy is `git pull` (or `bin/console app:deploy`).

> **Note:** any zip with the word "Compilation" in its name is `.gitignore`d. The DISA SRG-STIG sunset compilation bundle is 150 MB and exceeds GitHub's per-file limit; this rule prevents it from being accidentally re-committed.

---

## Tech notes

### Architecture choices

- **Read-only by design.** No database, no user accounts, no admin panel. The dataset is a directory of XML/JSON files. State changes happen by editing those files and pushing a commit.
- **No Doctrine, no ORM.** Symfony's framework bundle is loaded; the persistence stack is not.
- **Pre-computed indexes.** `stig_toc.json` and `scap_toc.json` carry title / version / release / release-date / severity counts so the index pages render without parsing thousands of XML files per request. The inverted search index under `resources/data/search/` is similar — built once, queried at request time.
- **Schema-driven for variation.** OSCAL Profile JSON drives baseline membership; per-family plan JSON drives the plan generator. Adding either is data, not code.
- **Browser-only editor surfaces.** The Plan Generator wizard, CKL Viewer, and Report Generator do all their parsing/editing/export in the browser. The server emits HTML and a JSON schema endpoint; user input never persists server-side.
- **8 themes via CSS custom properties.** `paper`, `ink`, `mono`, `solarized-light`, `solarized-dark`, `nord`, `dracula`, `high-contrast`. Stored in `localStorage`; a synchronous `<script>` in `<head>` applies the theme before first paint to avoid FOUC.
- **Self-hosted variable fonts.** Fraunces and IBM Plex Sans/Mono ship as latin-subset WOFF2 in `public/fonts/` — no Google Fonts request, no CDN dependency.
- **Per-page Open Graph + Twitter Card + JSON-LD.** Eight 1200×630 og:images (rendered from SVG via ImageMagick) cover home / stig / scap / cci / rmf / baselines / plans / ckl. Detail pages emit BreadcrumbList structured data.
- **Lazy on-disk caches.** STIG digests cache as `{xml}.digest.json` next to the source; invalidate on source-mtime change. Both this and the search-index cache are gitignored.

### What's in `src/`

| File | Role |
| --- | --- |
| `Controller/HomeController.php` | `/`, `/contactus`, `/report_generator`, `/search`, `/mission`, `/ckl-viewer` |
| `Controller/StigController.php` | `/stig*` routes including the diff engine, the `/stig/{...}` view (with embedded digest), and `/stig/feed.atom` |
| `Controller/ScapController.php` | `/scap*` routes including DISA scrape |
| `Controller/RmfController.php` | `/rmf/4`, `/rmf/5`, `/rmf/4-to-5`, `/baselines` |
| `Controller/CciController.php` | `/cci` |
| `Controller/PlansController.php` | `/plans`, `/plans/{family}`, `/plans/{family}/{schema,controls,preview,download}` |
| `Controller/VulnsController.php` | `/vulnerabilities*` — KEV catalog, IAVM/CTO list, single CVE/IAVM/KEV detail |
| `Controller/ApiController.php` | All `/api/*` JSON endpoints (including `/api/vulns/*`) |
| `Service/AppVersion.php` | Reads `/VERSION`; exposed as a Twig global |
| `Service/OverlayLoader.php` | OSCAL Profile JSON discovery, control → overlay membership map, family × baseline matrix for `/baselines` |
| `Service/R4ToR5Mapper.php` | Builds the r4 ↔ r5 mapping by reading both catalogs + harvesting `<incorporated-into>` from r5's withdrawn entries |
| `Service/StigCompanionZipFinder.php` | Resolves the DISA companion ZIP for a STIG via the XML-inside-ZIP index, lists its PDF entries, and streams individual PDFs |
| `Service/StigDigestBuilder.php` | Diffs current vs previous STIG version into a digest; on-disk cache via `.digest.json` |
| `Service/StigTocBuilder.php` | Parses STIG XML → toc entries with severity counts |
| `Service/ScapTocBuilder.php` | Same shape, for SCAP benchmarks |
| `Service/BulkDownloadIndex.php` | Sidecar `bulk_download_index.json` reader + builder — per-version XML/ZIP presence + size for the bulk-download table |
| `Service/BulkDownloadPlanner.php` | Validates a user's selection, dedupes shared-bundle paths, greedy-packs into ≤500 MB chunks, streams each chunk as a ZIP via ZipStream-PHP |
| `Service/SyncStatus.php` | Reads `sync_status.json`; exposed as Twig global |
| `Service/Plans/PlanRegistry.php` | Discovers plan schema files, exposes `availablePlans()` |
| `Service/Plans/ControlResolver.php` | Family + baseline → ordered list of controls + enhancements with `in_baseline` flags |
| `Service/Plans/PlanBuilder.php` | Schema + answers → renderable Plan structure (handles ODV substitution, dispositions, extras) |
| `Service/Plans/OdvExtractor.php` | Parses `[Assignment: …]`, `[Selection: …]`, `[Selection (one or more): …]` placeholders out of catalog text |
| `Service/Plans/Renderer/HtmlRenderer.php` | Plan → HTML preview |
| `Service/Plans/Renderer/DocxRenderer.php` | Plan → `.docx` via PHPWord |
| `Service/Search/IndexBuilder.php` | Builds the inverted search index (sharded JSON) |
| `Service/Search/Searcher.php` | Query-time index consumer (phrase + fuzzy fallback) |
| `Service/Vulns/KevLoader.php` | Reads the local CISA KEV mirror; CVE lookup + summary stats |
| `Service/Vulns/VulnsTocBuilder.php` | Walks STIG/SCAP XML, extracts IAVM/CTO/CVE refs into `vulns_toc.json` |
| `Service/Vulns/VulnsRegistry.php` | Read-side facade: combines KEV + corpus refs, builds in-memory pivot tables for the controller |
| `Twig/AppExtension.php` | Filters: `regex_replace`, `sha1`, `editorial_title`, `freshness_tag`, `rel_time` |
| `Command/StigRebuildTocCommand.php` | `app:stig:rebuild-toc` |
| `Command/ScapRebuildTocCommand.php` | `app:scap:rebuild-toc` |
| `Command/VulnsRebuildTocCommand.php` | `app:vulns:rebuild-toc` |
| `Command/StigRebuildCommand.php` | `app:stig:rebuild` (meta — runs stig + scap + vulns toc rebuilds) |
| `Command/KevRefreshCommand.php` | `app:kev:refresh` |
| `Command/CompanionZipRebuildIndexCommand.php` | `app:companion-zip:rebuild-index` |
| `Command/BulkDownloadRebuildIndexCommand.php` | `app:bulk-download:rebuild-index` |
| `Command/SearchRebuildCommand.php` | `app:search:rebuild` |
| `Command/AppVersionFreezeCommand.php` | `app:version:freeze` |
| `Command/DeployCommand.php` | `app:deploy` |

### Frontend

| File | Role |
| --- | --- |
| `public/css/app.css` | Full custom design system (~6,600 lines: tokens, components, layout, 8 themes, plus per-feature blocks for plans, CKL viewer, baselines, digests, saved searches, share button) |
| `public/js/app.js` | Vanilla JS for theme toggle, hamburger nav, sortable tables, paginated lists, Cmd/Ctrl+K search shortcut, share-this-page, save-search wiring |
| `public/js/saved_searches.js` | localStorage-backed saved-search bookmarks (dropdown + save/unsave toggle) |
| `public/js/plans.js` | Plan-generator wizard — autosave, BYOJSON, lazy controls fetch, ODV/disposition/extras collection |
| `public/js/ckl_viewer.js` | CKL/CKLB parser, XCCDF overlay, hand-rolled SVG charts, rule editor, CKLB export |
| `public/js/bulk_download.js` | Bulk-download selection state, header "select all of kind" toggles (operate on the full 3,970-row set, not just the Scroller viewport), plan request, sequential chunked download with progress |
| `public/js/report_generator.js` (within the legacy template) | Legacy report-generator pipeline |
| `public/og/*.png` | 8 generated 1200×630 social-share images, with SVG sources + a `build.sh` regenerator under `public/og/src/` |
| `templates/base.html.twig` | Layout shell — sticky header (search + share + theme + nav), footer, font preload, theme preflight, OG/Twitter/JSON-LD metadata blocks |
| `templates/macros.html.twig` | Reusable UI primitives: `ident()`, `sev()`, `sev_bar()`, `freshness()`, `cci_link()`, `rmf_link()` |

JavaScript dependencies the project ships (all self-hosted, no CDN): jQuery 3.7, Bootstrap 5 bundle, DataTables (incl. the Scroller plugin used by the bulk-download table), diff_match_patch, Masonry, js-cookie, moment.js, plus xlsx + zip + jmespath for the legacy report generator.

Server-side: `maennchen/zipstream-php` streams the bulk-download chunks without ever buffering them to disk or RAM — the only non-Symfony PHP dependency added since launch.

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

The application code in this repository is licensed under the **[GNU Affero General Public License, version 3 or later](LICENSE)** (AGPL-3.0-or-later). The full license text is in [`LICENSE`](LICENSE).

The compliance datasets it serves (STIGs, SCAP benchmarks, CCI catalog, NIST 800-53, OSCAL profiles) are public-domain works of the U.S. Government and are redistributed unmodified — the AGPL on the surrounding code does not apply to them. Self-hosted typefaces (Fraunces, IBM Plex) ship under the SIL Open Font License 1.1, and bundled JavaScript libraries each carry their upstream licenses (MIT, Apache-2.0, BSD-3-Clause).

The full attribution table — every code path, every dataset, every font, every JS library, with its license — is in [`NOTICE.md`](NOTICE.md).

**Practical summary:** you can read, run, fork, and modify this code. If you run a modified version as a network service, the AGPL requires you to publish your changes under the same license. The goal is to keep improvements in the commons.

The terms governing **use of the hosted service** at `cyber.trackr.live` are separate from the code license and live at [cyber.trackr.live/terms](https://cyber.trackr.live/terms).

---

## Acknowledgments

- **DISA Cyber Exchange** — for publishing the STIG and SCAP corpus openly.
- **NIST** — for the 800-53 catalog and OSCAL profile format.
- **The Symfony team** — for the framework that made the read-only architecture so easy.
- **PHPOffice / PHPWord** — for the DOCX writer powering the plan generator.
- **CTuX, Inspec, OpenRMF, and the broader DoD compliance tooling community** — for proving that the official sources weren't the last word on usability.
