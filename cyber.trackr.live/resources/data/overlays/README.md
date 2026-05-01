# RMF Overlays — OSCAL Profile data

This directory holds [OSCAL Profile][oscal-profile] documents that describe
*tailorings* of the NIST SP 800-53 Rev 5 control catalog. Each profile is
effectively a list of "use these controls, with these parameter values" and
serves as the data backing the **overlay filter** on `/rmf/5`.

## What's here

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
on the next request — no service registration needed. The metadata title is
used for the chip label (truncated at the first em-dash) and the file name
becomes the overlay ID after some lowercasing.

Candidates worth chasing:

- **CNSSI 1253** baselines (the 27 CIA-leg combinations). Currently published
  as PDFs and Excel; CNSS has an OSCAL working group but no canonical release.
- **FedRAMP Rev 5** baselines (Low / Moderate / High / LI-SaaS). Originally
  at `GSA/fedramp-automation` but that repo is gone — they've moved to a
  different rules format at `FedRAMP/rules`. Recoverable from a Wayback
  snapshot or transcribable from the FedRAMP PDF.
- **DoD overlays** (Privacy, Cross-Domain, Space Platform). Same situation
  as CNSS — PDF only.

[oscal-profile]: https://pages.nist.gov/OSCAL/concepts/layer/control/profile/
