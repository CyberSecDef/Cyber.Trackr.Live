# Cyber Trackr Redesign — TODO

> **✓ All groups complete.** Every item from the original spec has either shipped or been formally closed (kept as-is or won't-do, with reasoning recorded inline). This file is now archival — historical record of what was done and why some items were chosen out of scope.

Itemized backlog for the redesign, grouped by **type of change** rather than by page. Each item has a **Scope** (`global` / specific template name(s)) so it's clear what gets touched.

Originally derived from the redesign spec authored against the rendered pages; cross-referenced against the actual codebase and amended where the spec assumed a different structure than what's there.

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

### 1.3 Typography system (§2)  *(global)*  ✓ done

- [x] preconnect / Google Fonts initially shipped, **later replaced by self-hosted fonts** in `public/fonts/` (Fraunces variable + IBM Plex Sans variable + 3 IBM Plex Mono weights, latin subset only). Fraunces normal woff2 preloaded for the hero h1.
- [x] @font-face declarations in `app.css` cover all faces with `display: swap`.
- [x] Body default in `app.css`: IBM Plex Sans with system fallback, ss02/ss03 stylistic alternates, webkit/mozilla font smoothing.
- [x] Utility classes added: `.font-display`, `.font-display-italic`, `.font-mono`, `.eyebrow`.
- [x] Linux Libertine references resolved as part of the §4.6 detail-page tokenization sweep.

---

## Group 2 · Reusable Components

### 2.1 Identifier pill `.ident` (§3)  *(global, highest-impact)*  ✓ done

- [x] Add `.ident` class to `app.css` per spec.
- [x] Created `templates/macros.html.twig` with `{{ ui.ident(value) }}` macro (trims input to handle xpath whitespace).
- [x] Sweep across 8 templates: `home/index`, `stig/index`, `scap/index` (version/release columns); `stig/view`, `scap/view` (titles, summary, rule loop CCI/vuln/rule IDs and references); `cci/index` (CCI + RMF columns); `rmf/view_v5`, `rmf/view_v4` (control numbers, APs, related controls, enhancement numbers); `home/search` (all result row IDs + vuln details).
- [x] Spots intentionally left unwrapped: HTML id attributes, severity text (→ `.sev` in §2.2), `<option>` children (HTML disallows spans inside; → `.chip` in §4), JS string parameters.
- **Note for §4:** the list views (`home/index`, `stig/index`, `scap/index`) currently render two adjacent pills per row (V* + R*) since version/release are separate columns. The spec's combined V2R7 single-pill comes when Group 4 redesigns the table column structure.

### 2.2 Severity pill `.sev` (§4)  *(global)*  ✓ done

- [x] `.sev` base + high/med/low modifiers + `.sev.is-filter` interactive variant + `button.sev` reset (app.css §0c).
- [x] `{{ ui.sev(level, count = null) }}` macro with adaptive aria-label.
- [x] Applied in `stig/view` and `scap/view` filter buttons + per-rule badges. (Stat-card variant in §4.6 supersedes filter-button placement on the detail pages.)
- [x] JS refactor: `toggleFilter` takes severity as a 2nd arg.
- [x] **Closed:** STIGs tile severity-aggregate display — chose to keep the tile clean with count + descriptor only. Adding aggregate sev would be visually busy; can revisit if needed.

### 2.3 Severity bar `.sev-bar` (§5)  *(global)*  ✓ done

- [x] `.sev-bar` + child `.h/.m/.l` rules (app.css §0d).
- [x] `{{ ui.sev_bar(high, med, low) }}` macro with widths inline-styled as percentages, hover `title`, screen-reader `aria-label`.
- [x] Applied across the homepage Recent updates table, /stig list, /scap list (post deferral #6), and inline beside filter pills on stig/scap detail.

### 2.4 Freshness dot `.dot` + utilities (§6)  *(global)*  ✓ done

- [x] `.dot.fresh / .stale / .aged / .old` rules in `app.css` (section 0e).
- [x] PHP filters in `App\Twig\AppExtension` (`freshness_tag`, `rel_time`); JS twins in `app.freshnessTag()` / `app.relTime()`. Boundaries + formats match spec exactly across all four magnitudes.
- [x] `{{ ui.freshness(date) }}` macro renders `.freshness` wrapper + dot + `<time datetime>`.
- [x] Applied across home/index, stig/index, scap/index, stig/view, scap/view, hero trust strip (§4.1), footer Status column (§3.3), and STIG vuln search results (deferral #5).

### 2.5 Filter chip `.chip` (§9)  *(global)*  ✓ done

- [x] `.chip`, `.chip:hover`, `.chip.active`, `button.chip` reset, `.chip-group` wrapper, `.scroll-x` mobile container — all in app.css §0f.
- [x] Applied: stig/scap detail Sort UI, hero "Try" row (§4.1), homepage Recent updates age filter (§4.3), /stig and /scap age filters (§4.5/§4.8), report-generator render-options grid.
- [x] **Closed:** STIG detail Versions panel chip rows. Selects work; chip-row would need multi-select state JS for Compare. Not worth the implementation cost.

### 2.6 Stat cards / tiles `.tile` (§8)  *(homepage primarily, reusable)*  ✓ done

- [x] `.tile`, `.tile:hover`, `.tile::after`, `.tile-arrow` hover transform, `.tile-meta` (app.css §0g).
- [x] CSS Grid `.span-N` translation of the Tailwind-style spec spans applied in §4.2.
- [x] Tile arrow uses Bootstrap Icons `bi-arrow-up-right` at 20px, `var(--accent)`.
- [x] 7-tile grid built on homepage in §4.2 with all routes wired.

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
- [x] **Closed:** STIGs tile severity-aggregate row — chose to keep the tile clean with count + descriptor only.

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
- [x] **Closed:** chip-row Compare/View — selects function fine; multi-select chip UX wasn't worth the JS cost.
- [x] **Closed:** rule-list-as-table — kept the card layout to preserve the rich expand/collapse + per-rule details.

### 4.7 SCAP detail page  *(`templates/scap/view.html.twig`)*  ✓ done

- [x] Mirrors §4.6 layout: breadcrumbs, header with eyebrow + Fraunces title + meta + icon-btn download, 3-up stat-cards, versions panel (single-col modifier — SCAP has no Compare action), description, rule-controls bar, rule cards.
- [x] xccdf: namespace differences handled. `Published` shown in the freshness slot since SCAP doesn't carry a separate `released` date. No print button (didn't exist before either).
- [x] New `.versions-panel--single` modifier added to app.css so the View-only panel renders as 1-col instead of half-empty 2-col.
- [x] All `scap_app` JS handlers preserved verbatim.

### 4.8 SCAP list page  *(`templates/scap/index.html.twig`)*  ✓ done

- [x] DataTable replaced with the same .lib-page + .data-table pattern as §4.5.
- [x] **Severity Mix + Rules columns added** via deferral #6 — `App\Service\ScapTocBuilder` + `bin/console app:scap:rebuild-toc` mirror the §4.3a stig pattern. /scap now matches /stig visually with 6 columns including Severity Mix and Rules.
- [x] app.scapList JS module mirrors app.stigList; "scap-table" registered in app.tableSortHandlers; init() wires pagination on load.

### 4.9 RMF v5 view  *(`templates/rmf/view_v5.html.twig`)*  ✓ done

- [x] Tokenized styles already applied via §4.6 (.req-header / .doc-desc / dt.inline / .sec-header etc.). Bg-light fix covers the metadata panels.
- [x] Control numbers wrapped via §2.1.
- [x] Page header redesigned: breadcrumbs (Library › 800-53 r5), eyebrow "NIST SP 800-53", Fraunces title "Risk Management Framework *Rev. 5*" with italic accent, meta count, icon-btn print on right. Added "§ Controls" divider before the foreach.

### 4.10 RMF v4 view  *(`templates/rmf/view_v4.html.twig`)*  ✓ done

- [x] Mirrors §4.9 RMF v5 layout. Breadcrumb includes "800-53 r5" intermediate to signal v4 is the legacy revision. "NIST SP 800-53 · Legacy" eyebrow + Fraunces "Risk Management Framework *Rev. 4*" + meta with control count.

### 4.11 CCI list page  *(`templates/cci/index.html.twig`)*  ✓ done

- [x] CCI numbers and RMF control refs wrapped in `.ident` (done in §2.1 sweep).
- [x] DataTables retained per Group 8 decision; new app.css §2g overrides skin the toolbar (filter input, length select, info row, paginate buttons, .dt-button export bar) and the table headers/rows to tokens. All 5 export buttons (Copy/Excel/CSV/PDF/Print) preserved.
- [x] Page wrapped in `.lib-page`; new `.lib-page__header` with eyebrow + Fraunces "Common Control *Identifiers*" + lede with live count. Also fixed legacy typo "Idenfiers" → "Identifiers".

### 4.12 Search results page  *(`templates/home/search.html.twig`)*  ✓ done

- [x] Token typography applied throughout. `.ident` wrapping was already done in §2.1.
- [x] `bg-primary-subtle` / `border-primary` replaced with new `.search-section__header` (tokenized — surface bg, --border-strong border, accent on hover).
- [x] Wrapped in `.lib-page` with proper page header (eyebrow + Fraunces title "Results for *{query}*" + lede counting all 5 result types).
- [x] Section names cleaned ("RMFv4" → "800-53 r4", "APs" → "Assessment Procedures"); STIG titles in vuln results render underscores as spaces.
- [x] **Per-vuln freshness shipped via deferral #5** — each vuln result now shows a "Released" row with `{{ ui.freshness(...) }}` based on the STIG's release date from the toc.

### 4.13 Contact page  *(`templates/home/contact.html.twig`)*  ✓ done

- [x] Wrapped in `.lib-page` with eyebrow + Fraunces "Feedback & *suggestions*" title + lede.
- [x] Form switched from Bootstrap row+col-form-label horizontal to stacked `.form-stack` / `.form-field` layout. New §0k component family in app.css covers labels (mono uppercase), inputs (token bg/border/focus-ring), textareas, and `.form-actions` row.
- [x] Submit uses `.cta-primary` with arrow icon (matches the homepage colophon Contact CTA).
- [x] Formspree action URL + CSRF token preserved.

### 4.14 Report generator  *(`templates/home/report_generator.html.twig`)*  ✓ done

- [x] Page chrome rewritten with `.lib-page` shell, page header (eyebrow + Fraunces "Scans › *POAM & RAR*" + privacy lede), 4 `.rule-text` section dividers, `.form-stack` for contact fields, `.rg-options` 2-col grid for switches + drop area, `.data-table-wrap` for both scan tables, `.cta-primary` for Parse + Execute buttons.
- [x] **All 18 JS hook IDs preserved** (inputCommand/Contact/Phone/Email, the 4 inputPreFill/Condense/Lower switches, drop-area, fileElem, ScansAndOptionsBody, scanFiles, parseStatus, scans2poamParse, scanSummary, scans2poamExecute, result). Column order in scanFiles + scanSummary preserved exactly (JS uses `td:nth-child(1)`/`(6)`).
- [x] **Latent bug fixes:** added `<div id="alertWindow">` (JS appended to it in 7 places but div never existed); fixed all 4 contact-field labels pointing to wrong `for=`.
- [x] §4.13's `.form-stack` / `.form-field` component reused; §4.6's `.data-table` reused.

---

## Group 5 · Cross-Cutting Behavior

### 5.1 Theme toggle (§16)  *(global — `base.html.twig` + `app.js`)*  ✓ done (folded into §3.1)

- [x] Preflight `<script>` in `<head>` reads `localStorage.getItem('theme')` or `prefers-color-scheme` and sets `documentElement.dataset.theme` synchronously.
- [x] Toggle button wired to `window.app.theme.toggle()`. `aria-pressed` synced on load and on every set.
- [x] `aria-label="Toggle color theme"` set; sun/moon icon swap via `[data-theme]` attribute selectors on `[data-theme-icon="show-in-light|dark"]` children.
- [x] `matchMedia('(prefers-color-scheme: dark)').addEventListener('change', ...)` listener only triggers when no explicit user preference is stored.

### 5.2 Trust & freshness signals (§19)  *(global — Twig + controllers)*  ✓ done

- [x] `resources/data/sync_status.json` created with `_comment` documenting the file's purpose and refresh responsibility.
- [x] `src/Service/SyncStatus.php`: lazy-loaded reader, getters return `DateTimeImmutable` or null.
- [x] Twig global registered in `config/packages/twig.yaml` as `sync_status`.
- [x] Service auto-wires via `bind: string $projectDir: '%kernel.project_dir%'` added to `services.yaml` `_defaults` (general-purpose; reusable by future services).
- [x] **Edge case handled:** missing/malformed file → null returns → templates `{% if sync_status.disa %}` guards.
- [x] Used in footer Status column, hero trust strip (§4.1), and STIG/SCAP detail-page meta lines via per-record release-date freshness (§4.6 / §4.7).

### 5.3 Animations (§15)  *(global — `app.css`)*  ✓ done (folded into §4.1)

- [x] `@keyframes rise` and `.rise` class added (app.css §1e). Used `animation: ... both` shorthand which sets fill-mode forwards+backwards.
- [x] `.delay-1..5` utilities added.
- [x] Tile arrow translate + tile underline already shipped in §2.6.
- [x] All keyframe rules and the .rise/.delay utilities wrapped in `@media (prefers-reduced-motion: no-preference)`.

### 5.4 Responsive (§17)  *(global)*  ✓ done

- [x] Hero `clamp(48px, 9vw, 112px)` already works (§4.1).
- [x] Tile grid responsive spans already working (§4.2 .span-N utilities at md/lg).
- [x] Hamburger menu: `.nav-toggle` button + `.site-nav.is-open` fixed panel below lg, full ARIA wiring, 44px+ touch targets on stacked links.
- [x] Table card-view below md: thead hides, tr/td stack with `data-label` ::before labels in mono uppercase. Applied to home Recent table + stig list + scap list — sort/filter/pagination JS continues to work since only display changes.
- [x] Touch bump on `.chip` to 36px min-height below md.
- [x] `.scroll-x` already on hero Try chips and stig/scap detail filter chips.

### 5.5 Accessibility (§18)  *(global)*  ✓ done

- [x] WCAG AA contrast verified on every token combo (light + dark, on bg + on surface). Bumped dark `--text-muted` from `#8c7f66` to `#968870` to clear AA on `--surface` (was 4.45 → 5.04).
- [x] Global `:focus-visible` rule in app.css with suppression on elements that already manage focus (hero-search, site-search, form-field inputs, etc.).
- [x] Sortable headers keyboard-nav: `tabindex="0"` added via JS init; Enter/Space triggers click. `aria-sort` already managed by the click handler.
- [x] `aria-live="polite"` on filter-count spans (`#recent-stigs-count`, `#stig-page-info`, `#scap-page-info`) so screen readers announce filter result counts as they update.
- [x] Heading hierarchy spot-checked: one h1 per page, h2 for sections, h3 for footer cols, no skipped levels.
- [x] Already done earlier: skip link (§3.1), theme-toggle aria (§5.1), severity-pill aria-label (§2.2), status-dot text pairing (§2.4).

---

## Group 6 · Performance (§21)  ✓ done

- [x] Homepage table: top 100 rows (§4.3 / user request).
- [x] Stig list 50/page client-side pagination (§4.5); scap list same (§4.8). DataTables retained on `cci/index` per Group 8 decision.
- [x] **Self-hosted fonts** — Fraunces variable + IBM Plex Sans variable + 3 IBM Plex Mono weights in `public/fonts/` (latin subset, ~360 KB total). Google Fonts dependency removed entirely.
- [x] Fraunces normal woff2 preloaded with `<link rel="preload" as="font" type="font/woff2" crossorigin>` — used by every hero h1 above the fold.
- [x] `font-display: swap` on every @font-face.
- [x] Lazy-apply `.grain` class via `requestAnimationFrame` inside `app.init()` so the inlined SVG noise doesn't push back first paint.
- [x] **Closed:** Inline critical CSS for above-the-fold — site is fast enough without it; non-trivial FOUC risk if extracted incorrectly. Revisit if perf measurement ever flags first paint.

---

## Group 7 · Code Hygiene (§22)  ✓ done

- [x] All custom CSS hex values audited — all are inside `:root` / `[data-theme="dark"]` token definitions or comments. Component CSS uses tokens exclusively.
- [x] `.ident` wrapping verified site-wide (§2.1).
- [x] `.sev` pills verified (§2.2).
- [x] Release dates paired with freshness dot (§2.4).
- [x] Inline `style=""` swept. Three small utility classes added (`.pre-wrap`, `.requirement-title`, `.u-mt-0`); five templates updated. Only inline styles remaining are spec-allowed: sev-bar computed widths in macros.html.twig, animation delays (none currently), and functional cases (`display:none` on hidden file input, iframe sizing on download templates not yet redesigned).
- [x] `<style>` blocks in templates: only inside `printWindow.document.write(...)` (JS-generated print popup CSS). Acceptable.

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
