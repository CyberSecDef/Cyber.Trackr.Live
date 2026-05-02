# Cyber Trackr — TODO

## Future enhancements

A curated set of features that build on what's already shipping. All are
achievable on the current no-DB / schema-driven architecture; none
require user accounts or AI generation.

### r4 ↔ r5 control diff / mapping view

The 800-53 r4 → r5 transition is still real for many systems. Both
catalogs are already loaded. Build a side-by-side or "what merged into
what" view (e.g., r4 PE-9 → r5 PE-9 + PE-9(1) + PE-23) so practitioners
can plan migrations without manual cross-referencing.

- [ ] Author a `R4ToR5Mapper` service that loads both catalogs and
      produces a mapping table (1:1, 1:N, N:1, withdrawn).
- [ ] New route `/rmf/diff` (or `/rmf/4-to-5`) with two columns: r4
      catalog left, r5 catalog right; rows aligned by mapping.
- [ ] Per-row badges: "renamed", "merged with", "split into",
      "withdrawn", "new in r5".
- [ ] Deep-links into the existing r4 / r5 view pages from each side.
- [ ] Search box scoped to the mapping table.

### STIG "what changed" digest at DISA sync

When DISA pushes a new STIG version, practitioners want a one-page
summary of what's different — not a full version-to-version compare
page. Use the existing `stig_compare` machinery as the engine.

- [ ] At DISA-sync time (`stig_disa_download`), generate a digest JSON
      per newly-synced version: added rules, removed rules, severity
      changes, content changes by rule.
- [ ] New route `/stig/{title}/digest` showing the latest digest +
      links to prior digests.
- [ ] Atom / RSS feed at `/stig/feed.atom` listing recent digests
      across all STIGs (one entry per version published).
- [ ] Subscribe-friendly: each entry is self-contained with the digest
      summary so feed readers display it without opening the site.

### CKL / CKLB viewer

The full Report Generator parses CKL among other formats; a lighter
"drop a CKL, see what's in it" page is useful as a first stop before
running the full generator.

- [ ] New route `/ckl-viewer` (or named `ckl_viewer`).
- [ ] Drop-zone + paste-area that accepts CKL XML and CKLB JSON.
- [ ] Parse client-side (no upload) — same privacy posture as the Plan
      Generator wizards.
- [ ] Render: severity breakdown (CAT I / II / III), open / closed / NA
      counts, table of rules with status + finding details, links back
      to the corresponding STIG view.
- [ ] Persist nothing; the file stays in the browser.

### Coverage heat map per baseline

Visual aid for scoping a new system: see at a glance which families
contribute heaviest at each baseline (Low / Moderate / High / Privacy).

- [ ] New route `/baselines` (or section on the existing 800-53 page).
- [ ] Static SVG heat-map: 20 families × 4 baselines, cell color
      driven by control + enhancement count.
- [ ] Hover / tap reveals a control list for that family-baseline cell.
- [ ] Deep-links into the r5 catalog filtered by family + baseline.

### Privacy baseline as first-class wizard option

The Plan Generator engine already supports `nist-privacy` (PT and PM
render correctly there). Currently the baseline picker hides it —
discoverable only by URL-tweaking.

- [ ] Add `nist-privacy` to the wizard baseline `<select>` for every
      family (the engine handles "0 in-baseline cards" cleanly).
- [ ] Update the Plan Generator landing-page banner to call out PT and
      PM specifically as privacy-baseline plans.
- [ ] Document the baseline-selection guidance in the PT and PM plan
      schemas (the `_comment` already references this — surface it
      in-page).

### SEO + social-share metadata

The site has real reference content; making it show nicely when shared
on Slack, LinkedIn, GitHub, etc. would drive organic discovery without
any moderation cost.

- [ ] Per-route `og:title`, `og:description`, `og:image` in
      `base.html.twig`.
- [ ] `twitter:card` summary_large_image variants.
- [ ] One generated `og:image` per major surface — home, STIG, SCAP,
      CCI, RMF r5, Plan Generator.
- [ ] JSON-LD `WebSite` + `BreadcrumbList` structured data on
      browse-able pages.
- [ ] Verify against Lighthouse + the LinkedIn / Twitter / Facebook
      preview tools.

### Saved searches

localStorage-backed bookmarks for the global search, parallel to the
existing wizard-draft pattern.

- [ ] Add a "Save search" star icon to `/search` results pages.
- [ ] Saved searches surface as a dropdown / sidebar from the hero
      search box.
- [ ] Each entry stores the query string + any active filters; click
      to re-execute.
- [ ] Optional rename + delete from the dropdown.
- [ ] Persist nothing server-side; sync nothing across devices.

---

## Deferred (worth revisiting later)

These are tracked separately because they're meaningful but lower
priority than the items above:

- **OSCAL export for the Plan Generator** — federal direction of
  travel; would build on the existing renderer pattern.
- **Cross-family lint for plans** — catches inconsistencies between
  family plans (e.g., AC-2 cites Okta but PS-3 cites DCSA).
- **Multi-draft management in the wizard** — sidebar listing all
  localStorage drafts with rename / clone / delete.
- **PDF output for plans** — alongside DOCX.
- **General RSS feed for STIG/SCAP version publication** — broader
  than the digest-RSS above.
