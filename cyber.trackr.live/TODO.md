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

### 2.4 Freshness dot `.dot` + utilities (§6)  *(global)*

- [ ] Add `.dot.fresh / .stale / .aged / .old` rules to `app.css` per spec.
- [ ] **Decide where freshness logic lives**: Twig filter (PHP-side) vs JS. Two paths:
  - **Twig filter** (PHP): cleaner since dates are server-rendered. Create `App\Twig\AppExtension::freshnessTag()` and `::relTime()` filters. Use as `{{ s.released | freshnessTag }}` and `{{ s.released | relTime }}`.
  - **JS**: required if dates are sorted/filtered client-side after render (e.g., the age filter dropdown in §10).
  - **Recommend both**: Twig filters for static rendering, JS function for the client-side filter dropdown.
- [ ] Implement `freshnessTag(dateStr)` returning one of `fresh|stale|aged|old` based on days elapsed (≤365 / 365–1095 / 1095–1825 / >1825).
- [ ] Implement `relTime(dateStr)` returning a human string per spec: `<14d` → `"N days ago"`, `<60d` → `"N weeks ago"`, `<365d` → `"N months ago"`, `<10yr` → `"X.X years ago"` (one decimal), else integer years.
- [ ] Render pattern everywhere a date appears: `<span class="dot {{ tag }}"></span> · <time class="font-mono" datetime="{{ iso }}">{{ rel }}</time>`. Wrap in a Twig macro `{{ ui.freshness(date) }}` for one-line use.
- [ ] **Apply to:** STIG list rows (`home/index.html.twig`, `stig/index.html.twig`), STIG detail header (`stig/view.html.twig` line 25–26), SCAP equivalents, search results (`home/search.html.twig`), trust strip (§7), footer status (§12).

### 2.5 Filter chip `.chip` (§9)  *(global)*

- [ ] Add `.chip`, `.chip:hover`, `.chip.active` to `app.css`.
- [ ] Add `.scroll-x` mobile horizontal-scroll container with hidden scrollbar.
- [ ] Use in: hero "Try" row (§7), §10 table filter bar age dropdown (replace `<select>` with a chip group), §20 versions panel (replace `<select>` with chip rows), §22-style filter UI in `cci/index.html.twig` if added.

### 2.6 Stat cards / tiles `.tile` (§8)  *(homepage primarily, reusable)*

- [ ] Add `.tile`, `.tile:hover`, `.tile::after`, `.tile-arrow`, `.tile-arrow` hover transform to `app.css`.
- [ ] Decision: tiles are described with Tailwind-style spans (`col-span-12 lg:col-span-7`). On Bootstrap 5 these become `col-12 col-lg-7`. Translate spec §8 inventory:
  - STIGs tile: `col-12 col-lg-7`, `min-height: 260px` (custom), large title at 56px
  - 800-53 r5: `col-12 col-lg-5` (sits in right column above r4/CCIs split)
  - 800-53 r4: `col-12 col-lg-6` (half of right column under r5)
  - CCIs: `col-12 col-lg-6` (other half under r5)
  - Scans → Reports: `col-12 col-lg-5` horizontal layout
  - SCAP: `col-12 col-md-6 col-lg-4`
  - API: `col-12 col-md-6 col-lg-3`
- [ ] Tile arrow icon: use Bootstrap Icons `bi-arrow-up-right` styled to size 18–22px in `var(--accent)`.

---

## Group 3 · Layout Shell

### 3.1 Navigation header (§13)  *(global — `base.html.twig`)*

- [ ] **Decision: replace sidebar with top header (confirmed).** Header is `position: sticky` so it remains accessible on long detail pages. Delete `templates/sidebar.html.twig` and the `{% include "sidebar.html.twig" %}` at `base.html.twig:227`. Collapse the 4-quadrant grid in `base.html.twig` lines 199–233 to a simple `<header><main><footer>` shell.
- [ ] Build the new header in `base.html.twig`:
  - Sticky-eligible 64px height
  - Left: `.seal` 40px circle (concentric circles + dashed inner ring + "CT" monogram in Fraunces) + two-line label
  - Center (hidden below `lg`): horizontal nav from sidebar list — `STIGs`, `800-53 r5`, `800-53 r4`, `CCIs`, `SCAP`, `Scans → Reports`, `API`, `Contact`
  - Right: search trigger (icon + "Search" + `<kbd>⌘K</kbd>`), theme toggle button
- [ ] Add `.seal` component CSS (concentric circles via `::before` and `::after`).
- [ ] Add `<kbd>` styling rule to `app.css`: `font-mono`, small padding, surface background, 3px radius.
- [ ] When this lands: delete `templates/sidebar.html.twig` and the `{% include "sidebar.html.twig" %}` at `base.html.twig` line 227, simplify the body grid (currently 4 quadrants).

### 3.2 Atmospheric effects (§14)  *(global — `base.html.twig` + `app.css`)*

- [ ] Add `.grain::before` rule to `app.css` with SVG fractal noise data URI (light: `mix-blend-mode: multiply`, dark: `mix-blend-mode: screen`).
- [ ] Apply class `grain` to `<body>` in `base.html.twig`.
- [ ] Add `.rule-text` divider class for "horizontal rule with eyebrow text in middle" (used between sections on long pages).

### 3.3 Footer (§12)  *(global — `base.html.twig`)*

- [ ] Add `<footer>` markup at end of `base.html.twig` body (currently no footer exists at all).
- [ ] 4-column grid with sections: Library, Tools, About, Status. Use Bootstrap `row` + `col-6 col-md-3`.
- [ ] Status column wires to `LAST_DISA_SYNC` / `LAST_NIST_SYNC` Twig globals (§19 below).
- [ ] Bottom strip: small seal + `Cyber Trackr · est. MMXIX` (mono uppercase) on left, italic Fraunces tagline on right.

---

## Group 4 · Page-Specific Updates

### 4.1 Homepage hero (§7)  *(`templates/home/index.html.twig`)*

- [ ] Replace the entire current `{% block body %}` (lines 5–87) with new structure.
- [ ] Status stamp `.stamp`: `● Live · Updated daily` styled per spec (uppercase mono 9.5px, oxblood border).
- [ ] Issue-number sibling: `No. NN · YYYY` — generate dynamically from current month/year via Twig `now|date('m · Y')` or similar; spec uses publication-issue framing.
- [ ] Massive headline `<h1>`: "A complete reference / *for the cyber compliant.*" with `clamp(64px, 9vw, 112px)`, italic accent color on the second line.
- [ ] Subhead `<p>` at 17px, `max-width: 640px`, `--text-muted`.
- [ ] Hero search bar (`.hero-search`): 64px height, max-width 760px, focus ring per spec. Wires to existing `path('search', { query })` route.
- [ ] "Try" chip row with prefilled queries (Windows 11, RHEL 9, Kubernetes, AC-2, CCI-000196, macOS Sequoia). Each chip click sets the search input value and submits.
- [ ] Trust strip: three items separated by spacing — DISA sync dot, API status dot, dataset counts. Counts come from controller (count `$stigs`, count from CCI loader, etc.).
- [ ] `.rise` entrance animations with staggered `animation-delay`.

### 4.2 Homepage tile grid (§8)  *(`templates/home/index.html.twig`)*

- [ ] After hero, before recent STIGs table: insert the 12-column tile grid.
- [ ] Each tile links to its respective route (`path('stig')`, `path('rmf_v5_view')`, `path('rmf_v4_view')`, `path('cci')`, `path('report_generator')`, `path('scap')`, `path('api_summary')`).
- [ ] Aggregate counts (847 STIGs, 1,189 controls, 5,604 CCIs) come from the controller — `HomeController::index()` needs to be updated to compute and pass them.

### 4.3 Homepage recent STIGs table (§10)  *(`templates/home/index.html.twig`)*

- [ ] Replace the current Recent STIGs table (lines 28–66) and its DataTable init script with a **custom managed table per spec** (resolved decision: replace, not retain DataTables).
- [ ] Build the table HTML and CSS per spec §10: bordered container, sticky mono headers, sortable columns with `aria-sort`, row hover, footer row.
- [ ] Add filter bar above the table (text input + age `.chip` group). Wire client-side: text filter does case-insensitive substring match on Name + Version; age filter matches `freshness().tag`.
- [ ] Add the legend row (four `.dot` legend items: Fresh ≤1yr / Stale 1–3yr / Aged 3–5yr / Old >5yr).
- [ ] Add the footer row inside the bordered container: `Showing N of 847 STIGs` (left) + `View entire library →` link to `path('stig')` (right).
- [ ] Limit homepage table to top 30–40 rows. **Controller change required:** `HomeController::index()` should slice/sort `$stigs` to top 40 by released date; the full `path('stig')` page handles the rest.
- [ ] Add Severity Mix column data: high/med/low counts per stig (resolved decision: pre-compute and store in `stig_toc.json` — see Group 4.3a below).
- [ ] Add Rules total column (sum of high+med+low).

### 4.4 Homepage about/colophon (§11)  *(`templates/home/index.html.twig`)*

- [ ] Replace the "About Us" prose (lines 14–26) with the 12-column split: 4-col heading + 7-col pull quote + CTAs.
- [ ] Primary CTA → `path('contact_us')`.
- [ ] Secondary CTA "View on GitHub" → **placeholder** (resolved decision: no public repo yet). Render as `<a href="#" aria-disabled="true" data-coming-soon>...</a>` styled with reduced opacity. Revisit when repo exists.

### 4.3a Pre-compute severity counts in `stig_toc.json`  *(`StigController` — gates §4.3 and §4.5 Severity Mix columns)*

- [ ] Locate the toc generator code path in `StigController::stig()` (around lines 38–95) where each STIG XML is parsed when not already in the toc.
- [ ] Add three xpath calls per STIG: count rules at severity `high`, `medium`, `low` (e.g., `count(//xmlns:Rule[@severity='high'])`). Capture into the per-instance object alongside `version`, `release`, `date`, `released`, `filename`.
- [ ] Update the JSON schema written to `resources/data/stig_toc.json` to include `sev: { h: N, m: N, l: N }` per entry.
- [ ] **One-time backfill:** existing toc entries lack the new field. Two options:
  - Force-regenerate by deleting the toc file (slow first load).
  - Write a one-off command `bin/console app:stig:rebuild-toc` to re-parse all XML and update in place.
  - Recommend option 2 — explicit, reproducible.
- [ ] Verify Twig templates that consume `stigs[stig]` handle missing `sev` field gracefully during the transition (default to `{h:0, m:0, l:0}`).

### 4.5 STIG list page (`templates/stig/index.html.twig`)

- [ ] Replace the existing DataTable with the same custom managed table built for the homepage in §4.3 (resolved decision: replace DataTables here too). This page loads ALL stigs and is the "View entire library" target from the homepage footer link, so the table needs full pagination — implement client-side virtual scrolling or paginate at 50/page.
- [ ] Reuse the filter bar, legend row, mono headers, freshness dots in Released column, `.ident` pills in Version/Release from §4.3.
- [ ] Page header should use `.font-display` title with eyebrow above, replacing the `<h1>` at line 9.

### 4.6 STIG detail page (§20)  *(`templates/stig/view.html.twig`)*

- [ ] Add breadcrumbs at top: `STIGs › {vendor} › {name} › {version}` (vendor extraction from title is heuristic — may need a controller-side helper).
- [ ] Replace the `<h1>` at line 7 with `.font-display` title + freshness dot+date below + eyebrow above.
- [ ] Replace the `.doc-summary` card (lines 21–115) with a new layout:
  - Three severity stat-cards across the top (high/med/low) with severity color border-left, 36px Fraunces count, mono uppercase label
  - Versions panel (replace inline `<select>` controls at lines 81–96 and 100–113 with `.chip`-styled compare/view controls — `.chip` rows of version pills)
- [ ] Apply `.ident` to: vuln IDs (`group.attributes.id`), rule IDs (`group.Rule.attributes.id`), CCI numbers (`CCI_NUM`), RMF controls (the xpath result from the cci lookup), severity (replace text with `.sev` pill).
- [ ] Sticky table headers + mono header style for the rule list (currently each rule is rendered as a card-like block — see lines 121+ — not a table; either keep card layout but apply token-driven styling, or convert to table per spec).

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

### 5.1 Theme toggle (§16)  *(global — `base.html.twig` + `app.js`)*

- [ ] Preflight `<script>` in `<head>`, before any stylesheet, that reads `localStorage.getItem('theme')` (or system preference fallback) and sets `document.documentElement.dataset.theme` synchronously to prevent FOUC.
- [ ] Toggle button in header (§13) wired to `app.theme.toggle()` — flips `dataset.theme`, persists to `localStorage`, swaps icon (sun ↔ moon).
- [ ] `aria-label="Toggle color theme"` and `aria-pressed` on the button.
- [ ] `matchMedia('(prefers-color-scheme: dark)').addEventListener('change', ...)` listener for system changes when no explicit user preference is stored.

### 5.2 Trust & freshness signals (§19)  *(global — Twig + controllers)*

- [ ] Create `resources/data/sync_status.json` (resolved decision: explicit file, not mtime) with shape:
  ```json
  { "disa": "2026-04-26T00:00:00Z", "nist": "2026-04-18T00:00:00Z" }
  ```
- [ ] Document the file's purpose in a README comment at the top of the data dir (or the file itself can have a `// schema` field). Whatever process refreshes the source datasets is responsible for updating these timestamps — note in commit message that this is a manual update for now until automation exists.
- [ ] Create a service `App\Service\SyncStatus` that reads the JSON once per request (lazy) and exposes `getDisa(): \DateTimeImmutable` and `getNist(): \DateTimeImmutable`.
- [ ] Register as a Twig global in `config/packages/twig.yaml`:
  ```yaml
  twig:
      globals:
          sync_status: '@App\Service\SyncStatus'
  ```
  Then templates can use `{{ sync_status.disa | freshnessTag }}` and `{{ sync_status.disa | relTime }}`.
- [ ] Use in: hero trust strip (§7), footer Status column (§12), top of every STIG/control/CCI detail page.
- [ ] Edge case: if `sync_status.json` is missing, service returns null and templates render a fallback ("Sync status unavailable") rather than erroring.

### 5.3 Animations (§15)  *(global — `app.css`)*

- [ ] Define `@keyframes rise` and `.rise` class with 0.7s `cubic-bezier(0.2, 0.7, 0.2, 1)` easing and `animation-fill-mode: backwards`.
- [ ] Add `animation-delay` utilities `.delay-1` through `.delay-5` (0.05s, 0.15s, 0.25s, 0.35s, 0.45s) for stagger.
- [ ] Tile arrow translate transition (.tile-arrow on .tile:hover).
- [ ] Tile underline reveal `::after` scaleX 0→1.
- [ ] Wrap all keyframe animations and non-essential transitions in `@media (prefers-reduced-motion: no-preference)`.

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
