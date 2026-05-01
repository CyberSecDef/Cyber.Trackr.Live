# RMF Overlays — OSCAL Profile data

This directory holds [OSCAL Profile][oscal-profile] documents that describe
*tailorings* of the NIST SP 800-53 Rev 5 control catalog. Each profile is
effectively a list of "use these controls, with these parameter values" and
serves as the data backing the **overlay filter** on `/rmf/5`.

## What's here

### NIST 800-53 r5 baselines

| File | Source | Controls | Notes |
| --- | --- | --- | --- |
| `NIST_SP-800-53_rev5_LOW-baseline_profile.json` | NIST | 149 | Minimum controls for low-impact systems |
| `NIST_SP-800-53_rev5_MODERATE-baseline_profile.json` | NIST | 287 | Most common baseline; FedRAMP Moderate is a superset of this |
| `NIST_SP-800-53_rev5_HIGH-baseline_profile.json` | NIST | 370 | High-impact systems |
| `NIST_SP-800-53_rev5_PRIVACY-baseline_profile.json` | NIST | 96 | Privacy controls — overlaps L/M/H but not a strict subset |
| `NIST_SP-800-53_rev5_MODERATE-baseline-resolved-profile_catalog-min.json` | NIST | — | Resolved catalog (profile *applied* to the catalog). Reference only — `OverlayLoader` reads the profile form, not this. |

All sourced from
[`usnistgov/oscal-content`](https://github.com/usnistgov/oscal-content/tree/main/nist.gov/SP800-53/rev5/json),
metadata version **5.2.0**, last modified **2025-08-26**. Public domain
(work of the U.S. Government).

### FedRAMP Rev 5 baselines

| File | Controls | Set-parameters | Notes |
| --- | --- | --- | --- |
| `FedRAMP_rev5_LOW-baseline_profile.json` | 156 | 156 | FedRAMP Low — broader than NIST Low (adds 7 FedRAMP-specific controls) |
| `FedRAMP_rev5_MODERATE-baseline_profile.json` | 323 | 236 | The dominant cloud SaaS baseline |
| `FedRAMP_rev5_HIGH-baseline_profile.json` | 410 | 276 | High-impact cloud systems |
| `FedRAMP_rev5_LI-SaaS-baseline_profile.json` | 156 | 156 | Tailored Low Impact SaaS — for low-risk cloud services |

### CNSSI 1253 baselines (National Security Systems)

| File | Controls | Notes |
| --- | --- | --- |
| `CNSSI_1253_LOW-baseline_profile.json` | 230 | Controls required when any CIA leg is Low impact |
| `CNSSI_1253_MODERATE-baseline_profile.json` | 296 | Same, Moderate impact |
| `CNSSI_1253_HIGH-baseline_profile.json` | 357 | Same, High impact |
| `CNSSI_1253_PRIVACY-baseline_profile.json` | 75 | NSS Privacy Control Baseline (some NIST Privacy controls deselected) |
| `CNSSI_1253_CLASSIFIED-overlay_profile.json` | 113 | Attachment 5 — Classified System Overlay (additive, baseline-independent). Selects controls needed to safeguard classified information. |
| `CNSSI_1253_SPACE-overlay_profile.json` | 235 | Attachment 2 — Space Platform Overlay (Aug 2025). Effective baseline = CNSS HHH (357) + adds (82) − removes (204). Tailored for the unique constraints of on-orbit space NSS. |
| `cnssi-1253-axes.json` | (sidecar) | Per-axis baselines (cnss-c-low, cnss-i-mod, etc.) — 9 entries. Not loaded by `OverlayLoader`; preserved for future use if a per-axis chip toggle is added. |
| `cnssi-1253-controls-raw.json` | (sidecar) | Full parsed control map: title + per-axis booleans + privacy. Source-of-truth for the four baseline `_profile.json` files. |
| `cnssi-1253-overlay-decisions.json` | (sidecar) | Raw select/not-select lists from the Classified and Space overlay PDFs, plus the computed Space effective baseline. Useful if you ever want to render add/remove deltas separately. |

Sourced from **CNSSI 1253 (29 July 2022)**, the canonical document at
[cnss.gov/CNSS/issuances/Instructions.cfm](https://www.cnss.gov/CNSS/issuances/Instructions.cfm).
The baseline tables (Tables D-1 through D-20 in Appendix D of the PDF) were
extracted with `pdftotext -layout` and parsed by detecting the `L M H L M H L M H`
column header on each page, then sampling X / + markers at those positions for
each control row. Per the legend on PDF page 23, both `X` (inherited from a NIST
800-53B baseline) and `+` (added by CNSS for NSS) count as in-baseline; `--`
marks explicit deselection.

The four `_profile.json` files are **high-water-mark roll-ups** of the per-axis
baselines: a control belongs to "CNSS Low" if it appears in *any* of (C-Low,
I-Low, A-Low). This mirrors the NIST L/M/H model the user already sees on
`/rmf/5`. Per-axis fidelity is preserved in `cnssi-1253-axes.json` for a
future "advanced view" that exposes 9 axis-specific chips.

The Classified and Space overlay PDFs use a prose-paragraph format
(unlike the gridded baseline tables), so a separate parser
(`_tools/parse_cnssi_overlays.py`) handles them. Per the overlay docs:

- **Classified Overlay** is *baseline-independent and additive* — applies
  on top of any NSS baseline (Low / Moderate / High / Privacy) when the
  system handles classified information. Every control entry in the PDF
  is "Justification to Select"; we treat the overlay as a flat list of
  113 control IDs.
- **Space Platform Overlay** is *baseline-dependent* — explicitly tailors
  CNSS HHH by adding 82 space-specific controls and removing 204 that
  don't apply to the constrained on-orbit environment (controls flagged
  with "Justification to Not Select: Assumption — NOT GENERAL PURPOSE",
  "SPACE ENVIRONMENT", etc.). The OSCAL profile we ship is the *effective
  baseline* (HHH ∪ adds − removes = 235 controls), matching how an
  authorizing official would actually implement it.

Not included (require manual work or CAC-protected access):

- **CNSSI 1253 Privacy Overlay** — published separately; not yet parsed.
- **Cross Domain Solution Overlay** — FOUO, requires CAC/PIV to download
  from CNSS.

License: U.S. federal government work — public domain (CNSS is a U.S.
intelligence-community committee chaired by the National Security Agency).

Originally published at `GSA/fedramp-automation/dist/content/rev5/baselines/json/`.
**That repository was removed from GitHub in 2025** when FedRAMP transitioned to
their "Rev 5 + 20x" rules format. These profiles were recovered from the most
recent healthy Wayback Machine snapshots:

- LOW: snapshot `20250126171204`
- MODERATE: snapshot `20250618020019`
- HIGH: snapshot `20250320182412`
- LI-SaaS: snapshot `20250708083632`

License: U.S. federal government work — no copyright claim, public domain.
Unlike NIST baselines, FedRAMP profiles **do** bind organization-defined
parameter values via `modify.set-parameters` (e.g., AC-2(2) "disable
inactive accounts within 30 days"). Our `OverlayLoader` doesn't currently
surface those — it reads `with-ids` only — but the data is preserved in
the files for future use.

## Why OSCAL and not the XML's `<baseline>` tags

`resources/data/rmf/800-53v5-controls.xml` has `<baseline>LOW</baseline>` etc.
elements on each control — but that XML's `pub_date` is **2017-08-01**, eight
years stale. NIST has updated the catalog and baseline membership multiple
times since. OSCAL's 5.2.0 release is the current authoritative form, so
`OverlayLoader` reads from these files and the in-XML `<baseline>` tags are
no longer used by the renderer.

## OSCAL Profile shape (Phase 1 subset)

The interesting part of each profile, for our purposes:

```json
{
  "profile": {
    "metadata": {
      "title": "...MODERATE IMPACT BASELINE",
      "version": "5.2.0",
      "last-modified": "2025-08-26T15:10:16.00000-00:00"
    },
    "imports": [{
      "href": "#<catalog-uuid>",
      "include-controls": [
        { "with-ids": ["ac-1", "ac-2", "ac-2.1", "ac-2.2", ...] }
      ]
    }],
    "merge": { "as-is": true },
    "back-matter": { "resources": [{ "...catalog reference..." }] }
  }
}
```

For all four NIST baselines:

- `modify.set-parameters` is **empty** — NIST leaves organization-defined
  parameter (ODP) values to the implementing organization rather than
  binding them in the baseline.
- `modify.alters` is **empty** — no control text is rewritten.

So Phase 1 reduces to "which controls are in this overlay?" — a set
operation, no parameter resolution needed.

## Control ID format

OSCAL uses lowercase + dot notation. Our XML uses uppercase + parentheses
+ optional sub-letters (statement parts). `OverlayLoader::normalize()`
bridges them:

| XML form | OSCAL form | Notes |
| --- | --- | --- |
| `AC-2` | `ac-2` | Base control |
| `AC-2(1)` or `AC-2 (1)` | `ac-2.1` | Enhancement |
| `AC-1a.` | *(no match)* | Statement letter — part of AC-1, not a separate control |
| `AC-1a.1.(b)` | *(no match)* | Sub-statement — part of AC-1 |

Statement letters return `null` from `normalize()`; the loader treats them
as belonging to the parent control's overlay set.

## Adding new overlays later

Drop another OSCAL Profile JSON into this directory. The loader picks it up
on the next request — no service registration needed. The overlay ID is
derived from the filename:

- Source prefix: filenames starting with `NIST` → `nist-`, `FedRAMP` → `fedramp-`,
  anything else → `custom-`.
- Level suffix: substring match on `LI-SaaS`, `PRIVACY`, `MODERATE`, `HIGH`,
  `LOW` (in that order — `LI-SaaS` is checked first because it contains "LI"
  which would otherwise match "LOW").

Candidates worth chasing:

- **CNSSI 1253** baselines (the 27 CIA-leg combinations). Currently published
  as PDFs and Excel; CNSS has an OSCAL working group but no canonical release.
- **DoD overlays** (Cross-Domain, Space Platform, Intel-specific). PDF only.
- **CMMC Level 1/2/3** profiles. Some community OSCAL conversions exist;
  quality varies and needs verification before adoption (the
  `grcwarlock/oscal-catalog-library` repo, for example, is LLM-generated
  placeholders — `include-all: {}` rather than real `with-ids` lists —
  and should *not* be used).

[oscal-profile]: https://pages.nist.gov/OSCAL/concepts/layer/control/profile/
