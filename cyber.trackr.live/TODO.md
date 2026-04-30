# Cyber Trackr Redesign — TODO

Itemized backlog for the redesign, grouped by **type of change** rather than by page. Each item has a **Scope** (`global` / specific template name(s)) so it's clear what gets touched.

This document is the canonical task list. Mark items `[x]` as they're completed. Originally derived from the redesign spec authored against the rendered pages; cross-referenced against the actual codebase and amended where the spec assumed a different structure than what's there.

---

## Cross-reference notes (read first)

These are the assumptions in the original spec that needed adjustment based on actual source. Items below have been updated accordingly:

- **Stack:** Bootstrap **5.3.2** + Bootstrap Icons 1.11.1 + jQuery 3.7.1 + DataTables. No Tailwind. The spec uses Tailwind-style class names like `col-span-12 lg:col-span-7`, `gap-7`, `space-y-2.5`, `min-h-[260px]`, `lg:col-start-6`. These have been translated to Bootstrap 5 grid (`col-12 col-lg-7`, `g-3`) or noted as new custom utility classes that need to be added.
- **Navigation:** Currently a **left sidebar** (`templates/sidebar.html.twig` included from `base.html.twig` line 227). **Resolved:** replace with sticky top header per §13. The 4-quadrant body grid in `base.html.twig` lines 199–233 collapses to a simple `<header><main><footer>` shell.
- **Custom CSS:** Lives inline in `<style>` blocks in `templates/base.html.twig` (~150 lines covering `.doc-title`, `.doc-desc`, `.doc-summary`, `.sec-title`, `.sec-header`, `.req-header`, `.req-desc`, `.requirement`, `.reference`, `.sidemenu`, `.text-sm`, `.text-md`, `.body-container`, `.logo-text`, `.text-justify`) plus per-template `<style>` blocks (each list view repeats `#tableid td { padding:0px ... font-family: 'Linux Libertine', Georgia, Times, serif }`). All of this needs to migrate to a real stylesheet at `public/css/app.css` (new file) before tokenization can happen.
- **No theme system today:** All colors are hardcoded (Bootstrap defaults + a few inline `color: #339`, `#f6f6f6`, etc.). Theme tokens (§1) require introducing the stylesheet first.
- **Tables are DataTables, not static dumps:** The spec calls the homepage table a "250-row HTML table dump" — it actually IS a `<table>` populated by Twig with all stigs, but a `$('#stigs').DataTable({pageLength: 25})` call paginates client-side. So initial HTML payload is large but the visible UI is paginated. **Resolved:** replace DataTables on `home/index`, `stig/index`, `scap/index` with the custom managed table the spec describes (exact design match); retain + theme DataTables on `cci/index` (export buttons heavily used).
- **Google Fonts not loaded:** Need to add `<link>` tags for Fraunces + IBM Plex Sans + IBM Plex Mono in `base.html.twig` `<head>`.
- **No JS framework:** Plain jQuery + per-template `const stig_app = { init() {...} }` pattern. Theme toggle, freshness utility, etc. need to be added as a shared `public/js/app.js` (new file) loaded before the per-page scripts.
- **Server-side data for §19:** "Stored as a server-side variable (`window.LAST_DISA_SYNC`, etc.)" — implementation in Symfony will be either (a) a Twig global registered in `config/packages/twig.yaml` via `globals:` pointing at a service, or (b) injected by each controller. Recommend a Twig global for site-wide availability.
- **"Sitemap" mentioned in §6:** No `/sitemap` route exists today. There's a `public/sitemap.xml`. Treating "sitemap" as forward-compatible (the freshness component should work wherever, not requiring a new route).
- **Per-record freshness on detail pages (§19):** `stig/view.html.twig` already shows the published date (line 25) and a derived "Released" date (line 26 — currently uses `split('Date:')[1]`). The freshness dot should pair with the existing date display, not replace the whole header.

---

## Group 1 · Foundations

### 1.1 Stylesheet & file structure  *(global, prerequisite for everything below)*  ✓ done

- [x] Create `public/css/app.css` as the new home for all custom styles. Load it in `base.html.twig` *after* `bootstrap.min.css`, `bootstrap-icons.css`, `datatables.min.css` so it can override.
- [x] Move the inline `<style>` block from `base.html.twig` (lines 39–171) into `public/css/app.css`. Preserved every selector. Also moved the `@media print` rules from `stig/compare.html.twig` into a new section 4 of `app.css`.
- [x] Remove the per-template `<style>` blocks duplicated across `home/index.html.twig`, `stig/index.html.twig`, `scap/index.html.twig`; consolidated into a single rule (`#stigs td, #scaps td, #ccis td`) in `app.css`.
- [x] Create `public/js/app.js` with `window.app = { init, search }`. Theme toggle, freshness, relTime utilities will be added here in §5.1 / §2.4. Search route URL passed via `window.routes` set in `base.html.twig` (since static JS can't use Twig's `path()` helper).

### 1.2 Design tokens (§1)  *(global)*  ✓ done

- [x] Add `:root` block in `app.css` with all light-theme tokens.
- [x] Add `[data-theme="dark"]` block with dark-theme overrides. Theme toggle JS wiring still pending (§5.1).
- [x] Spacing scale: `--space-1` through `--space-10` (4, 8, 12, 16, 24, 32, 48, 64, 80, 112).
- [x] Border-radius scale: `--radius-sm` (2px) / `--radius-md` (3px) / `--radius-lg` (4px) / `--radius-xl` (6px).
- [x] Severity tokens per theme.
- [x] Freshness tokens per theme.
- [x] `body { background: var(--bg); color: var(--text); }`. Also updated `a { color: var(--accent) !important; }` and added `a:hover { color: var(--accent-hover) !important; }`.
- [x] Custom `::selection` color via `color-mix`.

### 1.3 Typography system (§2)  *(global)*  ✓ done (with noted deferrals)

- [x] Add `<link rel="preconnect">` for `fonts.googleapis.com` and `fonts.gstatic.com`.
- [x] Add `<link>` for: Fraunces (with `opsz` and `SOFT` variable axes), IBM Plex Sans (300/400/500/600/700), IBM Plex Mono (400/500/600). `display=swap` set.
- [ ] **Deferred to Group 6 (Performance):** Preload the Fraunces variable font woff2. Needs a stable URL — Google's CDN paths can change. Will revisit during the perf pass; either preload the gstatic URL directly (acceptable risk) or self-host the woff2 in `public/fonts/`.
- [x] Body default in `app.css`: IBM Plex Sans with system fallback, `font-feature-settings: 'ss02' on, 'ss03' on`, `-webkit-font-smoothing: antialiased`, `-moz-osx-font-smoothing: grayscale`.
- [x] Utility classes added: `.font-display`, `.font-display-italic`, `.font-mono`.
- [x] `.eyebrow` utility added.
- [ ] **Deferred to Group 4 (page redesigns):** Audit and replace existing `font-family: "Linux Libertine"` references. They're still in section 2 and 3 of `app.css` and will be removed when those classes are rewritten / those pages are redesigned per Group 4.

---

## Group 2 · Reusable Components

### 2.1 Identifier pill `.ident` (§3)  *(global, highest-impact)*  ✓ done

- [x] Add `.ident` class to `app.css` per spec.
- [x] Created `templates/macros.html.twig` with `{{ ui.ident(value) }}` macro (trims input to handle xpath whitespace).
- [x] Sweep across 8 templates: `home/index`, `stig/index`, `scap/index` (version/release columns); `stig/view`, `scap/view` (titles, summary, rule loop CCI/vuln/rule IDs and references); `cci/index` (CCI + RMF columns); `rmf/view_v5`, `rmf/view_v4` (control numbers, APs, related controls, enhancement numbers); `home/search` (all result row IDs + vuln details).
- [x] Spots intentionally left unwrapped: HTML id attributes, severity text (→ `.sev` in §2.2), `<option>` children (HTML disallows spans inside; → `.chip` in §4), JS string parameters.
- **Note for §4:** the list views (`home/index`, `stig/index`, `scap/index`) currently render two adjacent pills per row (V* + R*) since version/release are separate columns. The spec's combined V2R7 single-pill comes when Group 4 redesigns the table column structure.

### 2.2 Severity pill `.sev` (§4)  *(global)*  ✓ done (homepage tile aggregate deferred)

- [x] Add `.sev` base + `.sev.high`, `.sev.med`, `.sev.low` modifiers to `app.css` (section 0c). Plus `.sev.is-filter` interactive variant and `button.sev` chrome reset.
- [x] Twig macro `{{ ui.sev(level, count = null) }}` added to `macros.html.twig`. Renders short letter (H/M/L) + optional count, with adaptive aria-label.
- [x] `templates/stig/view.html.twig` filter buttons replaced; per-rule severity badge replaced. Decision on filter UI: kept the buttons in place and restyled (chose path A in the spec note rather than extracting to a separate `.chip` row) — that extract belongs to Group 4 when those pages get the full redesign.
- [x] `templates/scap/view.html.twig` same treatment.
- [x] **JS refactor:** `stig_app.toggleFilter` and `scap_app.toggleFilter` now take severity level as a 2nd argument instead of parsing visible button text. Old text-parsing approach was broken by the format change (`Low - 30` → `L · 30`).
- [ ] **Deferred to §4 (homepage):** aggregated severity displays on the STIG tile (§8). Will be added when the home tile grid lands and severity counts are pulled from `stig_toc.json` per §4.3a.

### 2.3 Severity bar `.sev-bar` (§5)  *(global)*  ✓ component done; spec uses pending Group 4

- [x] Add `.sev-bar` and `.sev-bar > .h/.m/.l` rules to `app.css` (section 0d).
- [x] Twig macro `{{ ui.sev_bar(high, med, low) }}` added. Renders nothing when total is zero; widths inline-styled as percentages; `title` for hover, `role="img"` + `aria-label` for screen readers.
- [x] **Bonus placement:** added inline beside the H/M/L filter pills on `stig/view` and `scap/view` for an immediate visual demo. Uses the same count vars set in 2.2.
- [ ] **Pending Group 4:** primary spec uses — STIG list Severity Mix column (§10) and STIG detail stat-cards (§20). Macro and CSS are ready; just needs the redesigned templates to consume them.

### 2.4 Freshness dot `.dot` + utilities (§6)  *(global)*  ✓ done (Group 4 placements pending)

- [x] `.dot.fresh / .stale / .aged / .old` rules in `app.css` (section 0e). `.fresh` gets a subtle glow via box-shadow + color-mix.
- [x] **Decision: both PHP and JS** (the recommended path). PHP filters in `App\Twig\AppExtension` (`freshness_tag`, `rel_time`) for server-rendered dates; JS twins in `app.freshnessTag()` / `app.relTime()` for client-side filtering. Behavior is identical so server- and client-rendered tags match.
- [x] `freshnessTag(date)` boundaries match spec exactly.
- [x] `relTime(date)` formats match spec exactly. Verified across all four magnitudes on the live STIG library.
- [x] `{{ ui.freshness(date) }}` macro added; uses a `.freshness` wrapper + dot + `<time datetime>`.
- [x] Applied to: `home/index` Released column, `stig/index` Released column, `scap/index` Date column, `stig/view` summary (Published + Released, with absolute date kept for legibility), `scap/view` Published.
- [ ] **Pending Group 4 placements:** trust strip on the hero (§7), footer Status column (§12), STIG result rows in search (`home/search.html.twig`).

### 2.5 Filter chip `.chip` (§9)  *(global)*  ✓ component done; spec uses pending Group 4

- [x] `.chip`, `.chip:hover`, `.chip.active`, `button.chip` reset, `.chip-group` wrapper added to `app.css` (section 0f).
- [x] `.scroll-x` mobile horizontal scroll container added.
- [x] **Bonus apply:** Sort UI on `stig/view` and `scap/view` (4-radio form-check group) replaced with `.chip-group` of role="radio" buttons. JS sort handlers updated to read `data-sort` and manage `.active` state.
- [ ] **Pending Group 4 placements:** hero "Try" row of prefilled queries (§7); STIG list table filter bar age dropdown (§10); STIG detail Versions panel chip rows (§20). Optional: filter UI on `cci/index`.

### 2.6 Stat cards / tiles `.tile` (§8)  *(homepage primarily, reusable)*  ✓ component done; consumed by §4.2

- [x] `.tile`, `.tile:hover`, `.tile::after`, `.tile-arrow` hover transform added to `app.css` (section 0g). Plus `.tile-meta` for the mono count/descriptor line.
- [x] **Bootstrap col-* translation** documented for §4.2 to use directly. Spec's Tailwind-style spans map to:
  - STIGs tile: `col-12 col-lg-7`, custom `min-height: 260px`
  - 800-53 r5: `col-12 col-lg-5`
  - 800-53 r4: `col-12 col-lg-6`
  - CCIs: `col-12 col-lg-6`
  - Scans → Reports: `col-12 col-lg-5` (horizontal layout)
  - SCAP: `col-12 col-md-6 col-lg-4`
  - API: `col-12 col-md-6 col-lg-3`
- [x] Tile arrow uses Bootstrap Icons `bi-arrow-up-right` at 20px, `var(--accent)`. Apply via `<i class="tile-arrow bi bi-arrow-up-right"></i>` inside the tile.
- [ ] **Pending §4.2:** tile grid built on the homepage. Tiles link to `path('stig')`, `path('rmf_v5_view')`, `path('rmf_v4_view')`, `path('cci')`, `path('report_generator')`, `path('scap')`, `path('api_summary')`.

---

## Group 3 · Layout Shell

### 3.1 Navigation header (§13)  *(global — `base.html.twig`)*  ✓ done

- [x] Sidebar replaced with sticky 64px top header. `templates/sidebar.html.twig` deleted; 4-quadrant body grid collapsed to `<header><main>` (footer comes in §3.3).
- [x] Header built per spec — three clusters (brand+seal / center nav / actions), responsive breakpoints applied.
- [x] `.seal` concentric circles (with dashed inner ring) added.
- [x] `<kbd>` global element styling added (and `.site-search__kbd` for header use).
- [x] **Print-routine fix:** `stig/view` had two `$("div#quadrant-4 > div.doc-title > h1")` selectors that would have broken with the layout change — switched to class-based `$("div.doc-title > h1").first()`.

### 3.2 Atmospheric effects (§14)  *(global — `base.html.twig` + `app.css`)*  ✓ done

- [x] `.grain::before` added (app.css §1b) — fixed full-viewport SVG fractal-noise layer, inlined as data URI. Light: multiply at 0.5 opacity. Dark: screen at 0.18 opacity. z-index 200 sits above the sticky header so the texture stays continuous; `pointer-events: none` keeps everything clickable.
- [x] `<body class="grain">` activates site-wide.
- [x] `.rule-text` section divider added (app.css §1c) — flex with 1px ::before/::after lines around the eyebrow.

### 3.3 Footer (§12)  *(global — `base.html.twig`)*  ✓ done

- [x] Footer markup added to `base.html.twig`. Uses CSS Grid (2-col → 4-col at md) instead of Bootstrap row, since the rest of the layout shell is custom-grid-based.
- [x] 4 columns: Library, Tools, About, Status — wired to existing routes; placeholders (`aria-disabled`) for Ruby gem and GitHub per Group 8 decision.
- [x] Status column reads from `{{ sync_status }}` Twig global (folded §5.2 in).
- [x] Bottom strip: 28px `.seal--small` + "Cyber Trackr · est. MMXIX" mono uppercase on left, italic Fraunces "Compliance, made legible." on right.

---

## Group 4 · Page-Specific Updates

### 4.1 Homepage hero (§7)  *(`templates/home/index.html.twig`)*  ✓ done

- [x] Replaced legacy "Welcome to Cyber Trackr" card. About Us + DataTable still below — handled by §4.4 / §4.3.
- [x] `.stamp` oxblood-bordered "● Live · Updated daily".
- [x] Issue line `No. {{ "now"|date("m") }} · {{ "now"|date("Y") }}`.
- [x] Massive headline with `clamp(48px, 9vw, 112px)` (started clamp at 48 instead of 64 for tighter mobile), italic oxblood accent on second line.
- [x] Subhead in Fraunces 17px, max-width 640px.
- [x] `.hero-search` 64px input with focus ring per spec, distinct `id="hero-search-input"` so it coexists with the header search.
- [x] Six "Try" chips including AC-2 and CCI-000196 wrapped in `{{ ui.ident() }}`.
- [x] Trust strip wired to `sync_status` global, dataset counts from new HomeController helpers (substring-count on raw XML for speed; uses `<controls:control` prefix since XML is namespaced).
- [x] `.rise` + `.delay-1..5` utilities applied. (§5.3 folded in.)

### 4.2 Homepage tile grid (§8)  *(`templates/home/index.html.twig`)*  ✓ done

- [x] 12-col CSS Grid (`.tile-grid__inner` with `repeat(12, 1fr)` + `.span-N` utilities) inserted between hero and the legacy About/table block.
- [x] Seven tiles linked to existing routes per spec.
- [x] Counts pulled from `stig_count` / `controls_count` / `cci_count` controller vars (added in §4.1).
- [ ] **Pending §4.3a:** the STIGs tile's "severity pill row showing aggregate" needs per-STIG sev counts pre-computed in `stig_toc.json`. Tile currently shows count + descriptor only.

### 4.3 Homepage recent STIGs table (§10)  *(`templates/home/index.html.twig`)*  ✓ done

- [x] Legacy Bootstrap+DataTable block removed. New custom managed table per spec.
- [x] Bordered container with sticky mono uppercase headers, sortable columns (aria-sort, click toggles asc/desc), row hover (--accent 5% mix), footer row.
- [x] Filter bar with 220px text input + 4-chip age group. Text filter does case-insensitive substring on name+version; age matches freshness_tag.
- [x] Legend row with the 4 dot+label freshness levels.
- [x] Footer row "Showing N of M STIGs" + "View entire library ›" link.
- [x] HomeController::index() slices to top 40 by released date (with date → released → 0 fallback).
- [x] Severity Mix column composes ui.sev_bar() + ui.sev() pills.
- [x] Rules total column with right-aligned tabular numerics.
- **Reusable .data-table component** also lives in app.css §2c — stig/index (§4.5) and scap/index (§4.8) will reuse it.

### 4.4 Homepage about/colophon (§11)  *(`templates/home/index.html.twig`)*  ✓ done

- [x] 12-col split: left (cols 1-4) eyebrow + Fraunces heading "Built by one engineer, *for the community.*"; right (cols 6-12, with col 5 as gutter via `grid-column: 6 / span 7`) pull-quote + body + CTA row. Original "About Us" copy preserved in tightened form.
- [x] Primary CTA "Contact & feedback" → `path('contact_us')`.
- [x] Secondary CTA "View on GitHub" rendered as `aria-disabled` placeholder with reduced opacity + title tooltip; revisit when repo exists.
- [x] New `.cta-primary` / `.cta-secondary` component added (app.css §0i) — distinct from Bootstrap's `.btn-*` to avoid collision; reusable anywhere CTAs appear.

### 4.3a Pre-compute severity counts in `stig_toc.json`  *(`StigController` — gates §4.3 and §4.5 Severity Mix columns)*  ✓ done

- [x] Extracted toc-build logic into `App\Service\StigTocBuilder` with `parseStig($filePath)` returning `[title, entry: {date, released, filename, version, release, sev: {h,m,l}}]` and `rebuildAll()` for full rescans.
- [x] Schema updated: every entry now carries `sev: {h, m, l}`.
- [x] One-time backfill via new `bin/console app:stig:rebuild-toc` command. Ran in 18.69s, 3,970 instances reparsed, 0 entries missing sev.
- [x] StigController::stig() refactored to use `$tocBuilder->parseStig()` for newly-dropped XML files (so future additions auto-include sev counts).
- [x] All Twig templates that read `stigs[]` use `s.sev.h|default(0)` fallback.

### 4.5 STIG list page (`templates/stig/index.html.twig`)  ✓ done

- [x] Old DataTable replaced with the same .data-table component as the homepage. Pagination is 50/page (kept the spec's option) implemented in app.stigList — filter/sort reset to page 1.
- [x] Reused .lib-page__bar / legend / .data-table everything from §4.3. CSS classes renamed from .recent-stigs__* to .lib-page__* for shared use across both pages.
- [x] Page header: .lib-page__title-large Fraunces with italic accent + .lib-page__lede subhead + eyebrow.
- [x] Bonus: extracted per-title rollup to `StigTocBuilder::latestPerTitle()`, reused in both HomeController and StigController. Generic sort-handler registry in app.tableSortHandlers makes the third (§4.8) and any future managed table trivial to plug in.

### 4.6 STIG detail page (§20)  *(`templates/stig/view.html.twig`)*  ✓ done

- [x] Breadcrumbs: simple "STIGs › {title}". Vendor heuristic deferred (would need a controller-side helper).
- [x] Header rewrite: eyebrow + Fraunces title from XCCDF Benchmark/title + meta row with `.ident` pill / freshness / released date / rule count, plus 40px `.icon-btn` download/print on the right.
- [x] `.doc-summary` card removed entirely. Replaced with: 3-up `.stat-cards` (severity border-left, big Fraunces count in matching color, mono uppercase label — clickable as filters via `stig_app.toggleFilter`); `.versions-panel` 2-col grid with Compare + View `<select>`s + CTA-primary submit; `.rule-controls` bar with sort chips + Expand-All toggle.
- [x] `.ident` and `.sev` already applied throughout the rule loop in Groups 2.1 / 2.2.
- [x] Rule-loop card markup preserved (existing JS depends on it). Supporting CSS in §2 fully tokenized — `.req-header` / `.req-desc` / `.sec-header` / `.doc-desc` / `dt.inline` / `.reference` all now use `--text` / `--border` / `--border-strong` / `--text-muted` instead of hardcoded grays.
- [ ] **Deferred:** chip-row replacement for the Compare/View `<select>` controls. Current selects work; chip-row UX would need multi-select state JS for Compare. Future enhancement.
- [ ] **Deferred:** rule-list-as-table. Spec offered "either keep card or convert to table" — chose card to preserve the rich expand/collapse + per-rule details.

### 4.7 SCAP detail page  *(`templates/scap/view.html.twig`)*

- [ ] Apply same patterns as STIG detail (§20 / 4.6 above).

### 4.8 SCAP list page  *(`templates/scap/index.html.twig`)*

- [ ] Replace the existing DataTable with the same custom managed table pattern from §4.5 (resolved decision: replace DataTables here too). Same column conventions (Name, Version `.ident`, Released with freshness dot, etc.) — though SCAP doesn't currently track severity in its toc, so the Severity Mix column may be omitted or also pre-computed in a `scap_toc.json` parallel to §4.3a.

### 4.9 RMF v5 view  *(`templates/rmf/view_v5.html.twig`)*

- [ ] Apply tokenized styles to existing card layout.
- [ ] Wrap control numbers (`{{control.number}}` etc.) in `.ident`.
- [ ] Eyebrow + Fraunces title for the page header.

### 4.10 RMF v4 view  *(`templates/rmf/view_v4.html.twig`)*

- [ ] Same as RMF v5.

### 4.11 CCI list page  *(`templates/cci/index.html.twig`)*

- [ ] Wrap CCI numbers (`{{r.cci}}` line 25) and RMF control refs (`{{r.rmf}}` line 30) in `.ident`.
- [ ] **Keep DataTables export buttons here** — they're heavily used (Excel/CSV/PDF/Print export at lines 49–77). Just restyle the toolbar with token colors.
- [ ] Eyebrow + Fraunces title replacing the `<h1>` at line 7.

### 4.12 Search results page  *(`templates/home/search.html.twig`)*

- [ ] Apply token typography and `.ident` wrapping throughout the four collapsible result sections (RMFv4, RMFv5, CCI, STIG).
- [ ] Replace `bg-primary-subtle` / `border-primary` Bootstrap classes on the section headers with tokenized custom styles.
- [ ] Add freshness dots to STIG results.

### 4.13 Contact page  *(`templates/home/contact.html.twig`)*

- [ ] Apply token typography and a clean, tokenized form layout. (Skim file first to see what's there — not yet read in this session.)

### 4.14 Report generator  *(`templates/home/report_generator.html.twig`)*

- [ ] Apply token typography. (Skim file first.)

---

## Group 5 · Cross-Cutting Behavior

### 5.1 Theme toggle (§16)  *(global — `base.html.twig` + `app.js`)*  ✓ done (folded into §3.1)

- [x] Preflight `<script>` in `<head>` reads `localStorage.getItem('theme')` or `prefers-color-scheme` and sets `documentElement.dataset.theme` synchronously.
- [x] Toggle button wired to `window.app.theme.toggle()`. `aria-pressed` synced on load and on every set.
- [x] `aria-label="Toggle color theme"` set; sun/moon icon swap via `[data-theme]` attribute selectors on `[data-theme-icon="show-in-light|dark"]` children.
- [x] `matchMedia('(prefers-color-scheme: dark)').addEventListener('change', ...)` listener only triggers when no explicit user preference is stored.

### 5.2 Trust & freshness signals (§19)  *(global — Twig + controllers)*  ✓ done (footer wired; hero/detail pending Group 4)

- [x] `resources/data/sync_status.json` created with `_comment` documenting the file's purpose and refresh responsibility.
- [x] `src/Service/SyncStatus.php`: lazy-loaded reader, getters return `DateTimeImmutable` or null.
- [x] Twig global registered in `config/packages/twig.yaml` as `sync_status`.
- [x] Service auto-wires via `bind: string $projectDir: '%kernel.project_dir%'` added to `services.yaml` `_defaults` (general-purpose; reusable by future services).
- [x] **Edge case handled:** missing/malformed file → null returns → templates `{% if sync_status.disa %}` guards.
- [x] Used in footer Status column.
- [ ] **Pending Group 4 placements:** hero trust strip (§7); top of every STIG/control/CCI detail page (§19 spec calls for "Per-record freshness on detail pages"). Macro and globals are ready.

### 5.3 Animations (§15)  *(global — `app.css`)*  ✓ done (folded into §4.1)

- [x] `@keyframes rise` and `.rise` class added (app.css §1e). Used `animation: ... both` shorthand which sets fill-mode forwards+backwards.
- [x] `.delay-1..5` utilities added.
- [x] Tile arrow translate + tile underline already shipped in §2.6.
- [x] All keyframe rules and the .rise/.delay utilities wrapped in `@media (prefers-reduced-motion: no-preference)`.

### 5.4 Responsive (§17)  *(global)*

- [ ] Verify hero clamp value works at all breakpoints.
- [ ] Tile grid: confirm `col-12 col-lg-*` translation collapses correctly.
- [ ] Below `lg`: replace center nav with hamburger (defer to a follow-up — out of scope for first pass).
- [ ] Below `md`: switch table to "card view" — each row a stacked block with labeled fields. Bootstrap doesn't have this primitive; needs a CSS media query toggling display from `table-row` to `block` and adding `::before` content for labels.
- [ ] Touch-target audit: every interactive element ≥ 44px tall.
- [ ] `.scroll-x` on mobile chip rows.

### 5.5 Accessibility (§18)  *(global)*

- [ ] Color contrast audit on every token combination (`--text` / `--bg`, `--text-muted` / `--bg`, severity tokens on backgrounds). Target WCAG AA. May need to darken `--text-muted` or `--accent` after first render.
- [ ] Global focus style: `:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px }`.
- [ ] Sortable headers: `role="button"`, `tabindex="0"`, `aria-sort`, keyboard `Enter`/`Space` activation. (DataTables handles much of this — verify with screen reader.)
- [ ] Theme toggle aria attributes (covered in 5.1).
- [ ] Status dots paired with text labels (the relative-time string). Never color-only meaning.
- [ ] Severity pills have `aria-label="High severity, N rules"`; visual `H · N` is decorative.
- [ ] Skip link: `<a href="#main" class="visually-hidden-focusable">Skip to content</a>` first inside `<body>`.
- [ ] Heading hierarchy: one `<h1>` per page (hero on home, page title elsewhere). `<h2>` for sections, `<h3>` for tiles.

---

## Group 6 · Performance (§21)

- [ ] Homepage table: ship only top 30–40 rows (controller change in §4.3 above).
- [ ] Full STIG list at `path('stig')`: keep DataTables client-side pagination for now. If the loaded HTML grows unwieldy (>1MB), switch to server-side pagination (DataTables Ajax mode) — defer until measured.
- [ ] Inline critical CSS for above-the-fold (hero, header). Either via a Symfony asset bundler or hand-extracted block in `<head>`.
- [ ] `font-display: swap` on Fraunces.
- [ ] Preload the variable Fraunces font file.
- [ ] Lazy-load grain SVG noise (apply `.grain` class after `DOMContentLoaded` so first paint isn't delayed).

---

## Group 7 · Code Hygiene (§22)

- [ ] All custom CSS uses tokens — grep `app.css` for hardcoded hex values once written; replace.
- [ ] All identifiers wrapped in `.ident` — covered in §2.1 sweep above; verify with grep over templates after the fact.
- [ ] All severity references use `.sev` pills — covered in §2.2.
- [ ] All release dates paired with freshness dot — covered in §2.4 application list.
- [ ] No inline `style=""` attributes except for: severity bar widths (computed), animation delays (per-element timing). Sweep with grep over `templates/`.
- [ ] No `<style>` blocks inside templates (move all to `app.css`) — covered in §1.1.

---

## Group 8 · Resolved Decisions

All decisions resolved 2026-04-28. Captured here for traceability; affected sections above have been updated.

- [x] **Top nav vs sidebar** (§3.1) → **Replace sidebar with top nav.** Header gets `position: sticky` to compensate for losing always-visible nav on long pages.
- [x] **DataTables retain vs replace** (§4.3, §4.5, §4.8, §4.11) → **Replace** on `home/index`, `stig/index`, `scap/index`; **retain + theme** on `cci/index` (export buttons heavily used).
- [x] **Severity counts in `stig_toc.json`** (§4.3) → **Pre-compute.** Extend the toc generator in `StigController` to capture high/med/low per stig at parse time. One-time backfill required for existing entries.
- [x] **GitHub link for colophon CTA** (§4.4) → **Placeholder.** No public repo yet; render as `href="#"` with `aria-disabled="true"` or `data-coming-soon`. Revisit when repo exists.
- [x] **Sync status source** (§5.2) → **Explicit `sync_status.json`** at `resources/data/sync_status.json` with shape `{"disa": "ISO-date", "nist": "ISO-date"}`. Updated by whatever process refreshes source data; loaded once and exposed as Twig globals.
- [x] **Issue-number format** (§4.1) → **Auto from current date** via `{{ "now"|date("m · Y") }}`, prefixed with `No. ` — purely decorative magazine framing, no semantic meaning.

---

## Suggested execution order

The spec recommends starting with §1 (tokens), §2 (typography), §3 (idents), §6 (freshness). Translated to this doc:

1. **Group 1** entirely (foundations — stylesheet structure, tokens, typography). Prerequisite for everything else.
2. **Group 2.1** (`.ident`) and **2.4** (freshness dots) — highest visual ROI sweeps.
3. **Group 2.2–2.3, 2.5–2.6** (remaining components).
4. **Group 3** (layout shell — header, footer, atmospheric).
5. **Group 4** by page priority — home → STIG list → STIG detail → others.
6. **Group 5** (cross-cutting: theme toggle, animations, a11y, responsive) interleaved with Group 4 work.
7. **Group 6** (performance) once content is in place.
8. **Group 7** (final cleanup sweep).
