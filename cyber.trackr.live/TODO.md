# Cyber Trackr — TODO

## Active backlog

_(empty — see "Shipped this iteration" below)_

---

## Shipped this iteration

The seven items from the post-plan-generator review all landed:

- **r4 ↔ r5 control diff** — `/rmf/4-to-5` with side-by-side rows,
  withdrawal targets harvested from r5's own `<incorporated-into>`
  metadata, deep-links to /rmf/4 and /rmf/5.
- **STIG "what changed" digest + Atom feed** — collapsible Digest
  of Updates section on every multi-version STIG view; Atom feed
  at `/stig/feed.atom` for the 25 most recent versions; on-disk
  cache via `{xml}.digest.json` files.
- **CKL / CKLB viewer** — `/ckl-viewer`, browser-only editor with
  SCAP overlay and CKLB export.
- **Baseline coverage heat map** — `/baselines`, 20×4 grid with
  per-baseline tinting, click-through to control lists.
- **Privacy baseline UX** — wizard dropdown grouped by source with
  friendly labels + counts; PT and PM cards badged on plan-index;
  in-wizard hint when family is PT or PM.
- **SEO + social-share metadata** — Open Graph + Twitter Card +
  JSON-LD on every page; 8 generated 1200×630 og:images.
- **Saved searches** — localStorage-backed bookmarks; dropdown on
  hero search + /search page; rename + delete.

---

## Deferred (worth revisiting later)

These are meaningful but lower priority. Pull from this list when
the active backlog is empty.

- **OSCAL export for the Plan Generator** — federal direction of
  travel; would build on the existing renderer pattern.
- **Cross-family lint for plans** — catches inconsistencies between
  family plans (e.g., AC-2 cites Okta but PS-3 cites DCSA).
- **Multi-draft management in the wizard** — sidebar listing all
  localStorage drafts with rename / clone / delete.
- **PDF output for plans** — alongside DOCX.
- **General RSS feed for STIG/SCAP version publication** — broader
  than the digest-RSS already shipped.
