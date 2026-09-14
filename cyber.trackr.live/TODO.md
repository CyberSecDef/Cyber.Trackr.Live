# Cyber Trackr — TODO

## Active backlog

- **Decide the fate of the 7 non-CCI og:images.** The 2026-09-14
  toolchain fix regenerated all eight cards, so `home`, `stig`,
  `scap`, `rmf`, `baselines`, `plans`, and `ckl` now render in the
  navy/serif design their SVGs actually describe rather than the
  black/sans output the old renderer produced. Keep them, or
  `git checkout` those seven to ship the CCI terminology fix alone.
- **Georgia is not installed on the build host**, so card titles
  resolve to Noto Serif. Fine as-is; `ttf-mscorefonts-installer`
  would get literal Georgia but is EULA-interactive.

---

## Shipped 2026-09-14

- **CCI terminology corrected site-wide.** Reader feedback flagged
  "Common Control Identifier" as wrong; DISA's term — and what
  `README.md` and `NOTICE.md` already said — is **Control
  Correlation Identifier**. Fixed in six places: the `/cci` page
  `og:title`, eyebrow and `<h1>`; the home-page CCI tile eyebrow;
  the `/cci` summary in `ApiController`; and the og:image source.
  `Common Control Inheritance` in the plan renderer is the separate
  NIST concept and was deliberately left alone.
- **og:image build toolchain fixed** (`public/og/src/build.sh`).
  Two latent bugs: the script didn't emit the section comments its
  committed SVGs carry, so any run stripped 13 lines from all eight
  sources; and with `rsvg-convert` absent, ImageMagick silently fell
  back to its internal MSVG renderer, which drops the brand rule,
  flattens the `#0f172a` ground to black and swaps the Georgia
  titles for a sans face. Script now emits the comments (SVGs
  round-trip byte-identically) and hard-fails without librsvg.
  Installed `librsvg2-bin` on the build host. PNGs ~130KB → ~60KB.

---

## Shipped — post-plan-generator review

The seven items from that review all landed:

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
