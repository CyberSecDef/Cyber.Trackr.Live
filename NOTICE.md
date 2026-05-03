# Notices and Attributions

`cyber.trackr.live` ships a mix of original code, U.S. Government public-domain
data, and third-party assets. Each is licensed independently and the rest of
this file walks through them.

---

## Original code — AGPL-3.0-or-later

Everything under `cyber.trackr.live/src/`, `cyber.trackr.live/templates/`,
`cyber.trackr.live/public/css/`, `cyber.trackr.live/public/js/app.js`,
`cyber.trackr.live/public/js/saved_searches.js`,
`cyber.trackr.live/public/js/plans.js`,
`cyber.trackr.live/public/js/ckl_viewer.js`,
`cyber.trackr.live/public/og/src/`, and the project's own configuration
(`composer.json`, `bin/*.sh`, route YAML, etc.) is licensed under the
**GNU Affero General Public License, version 3 or any later version**.

A copy of the AGPL-3.0 is included in this repository as `LICENSE`. The full
text is also available at <https://www.gnu.org/licenses/agpl-3.0.txt>.

Copyright © 2019–present Robert Weber.

If you run a modified version of this code as a network service, the AGPL
requires that you offer the modified source to your users. That clause is the
whole reason this project picked AGPL: the goal is to keep improvements in the
commons, not to gate use.

---

## Compliance datasets — U.S. Government public domain

Everything under `cyber.trackr.live/resources/data/` that originates from a
U.S. federal source is a **work of the United States Government** and is
in the **public domain** within the U.S. (per 17 U.S.C. § 105). It is
redistributed here unmodified.

| Path | Source | Notes |
| --- | --- | --- |
| `stig/`, `zips/U_*_STIG.zip` | DISA Cyber Exchange — STIG releases | XCCDF XML; permalinks reconstructed but content is byte-for-byte the DISA original |
| `scap/`, `zips/U_*_SCAP*.zip` | DISA Cyber Exchange — SCAP benchmarks | XCCDF + OVAL XML, redistributed as published |
| `cci/U_CCI_List.xml`, `cci/U_CCI_List_2024.xml` | DISA — Control Correlation Identifiers | Both catalogs included unmodified |
| `rmf/800-53v4-controls.xml`, `rmf/800-53v5-controls.xml` | NIST SP 800-53 r4 / r5 | Hand-converted from the NIST OSCAL release; structure preserved, text unmodified |
| `overlays/*_profile.json` | NIST + FedRAMP + CNSSI 1253 baselines | OSCAL Profile JSON, redistributed as-is |
| `800-53r4.json` | NIST SP 800-53 r4 | JSON form for the API, derived from the NIST source |

Public-domain status applies inside the United States; some other
jurisdictions handle U.S. Government works differently — when in doubt,
treat them as freely usable but cite NIST or DISA as the original source.

The AGPL on the surrounding code does **not** retroactively license the
public-domain data. A downstream user who only wants the data is free to take
it without taking the code.

### Curated additions on top of public-domain data

A handful of files in `resources/data/` are original work by this project,
not government data:

| Path | Contents |
| --- | --- |
| `rmf/r4-to-r5-mapping.json` | Optional rationale text on top of the mechanical r4 ↔ r5 inference |
| `plans/*.json`, `plans/_shared.json` | Per-RMF-family plan-generator schemas |
| `stig_toc.json`, `scap_toc.json` | Pre-computed indexes derived from DISA XML |
| `sync_status.json` | Manual sync timestamp |

These are licensed under AGPL-3.0-or-later along with the rest of the code.

---

## Self-hosted typefaces — SIL Open Font License 1.1

| Family | Files | Source |
| --- | --- | --- |
| **Fraunces** | `cyber.trackr.live/public/fonts/fraunces-*.woff2` | [Fraunces on GitHub](https://github.com/undercase/Fraunces) |
| **IBM Plex Sans** | `cyber.trackr.live/public/fonts/ibm-plex-sans-*.woff2` | [IBM Plex on GitHub](https://github.com/IBM/plex) |
| **IBM Plex Mono** | `cyber.trackr.live/public/fonts/ibm-plex-mono-*.woff2` | [IBM Plex on GitHub](https://github.com/IBM/plex) |

All three ship under the **SIL Open Font License 1.1** which is compatible
with redistribution. Subsetted to Latin glyphs only; no other modifications.
The OFL-1.1 text is at <https://openfontlicense.org/open-font-license-official-text/>.

---

## Bootstrap Icons

`cyber.trackr.live/public/css/bootstrap-icons.css` and the corresponding
font files are part of [Bootstrap Icons](https://icons.getbootstrap.com/),
licensed under the **MIT License**.

---

## Third-party JavaScript libraries

All third-party JS is self-hosted under `cyber.trackr.live/public/js/` (no
CDN dependencies). Each ships under its own upstream license:

| File | Project | License |
| --- | --- | --- |
| `jquery-3.7.1.min.js` | jQuery 3.7.1 | MIT |
| `bootstrap.bundle.min.js` | Bootstrap 5 | MIT |
| `datatables.min.js`, `datatables.min.css` | DataTables | MIT |
| `diff_match_patch.js` | google-diff-match-patch | Apache-2.0 |
| `dist_masonry.pkgd.min.js` | Masonry | MIT |
| `js.cookie.min.js` | js-cookie | MIT |
| `xlsx.full.min.js` | SheetJS Community Edition | Apache-2.0 |
| `moment.js` | Moment.js | MIT |
| `jmespath.js` | JMESPath JavaScript | Apache-2.0 |
| `zip.js`, `deflate.js`, `inflate.js` | gildas-lormeau/zip.js | BSD-3-Clause |

Their license texts live within their respective minified source files where
the upstream maintainers included them.

---

## PHP dependencies

PHP packages installed via Composer (Symfony, Twig, PHPWord, etc.) are not
redistributed in this repository — they're pulled into `vendor/` at install
time. Each package carries its own license; the consolidated list is in
`cyber.trackr.live/composer.lock`. Notable upstreams:

- **Symfony 7.4 LTS** — MIT
- **Twig 3** — BSD-3-Clause
- **PHPOffice / PHPWord** — LGPL-3.0

---

## Hosted service

The code license governs the bytes in this repository. The **terms of use
for the hosted service at `cyber.trackr.live`** are separate and live at
<https://cyber.trackr.live/terms>. If you only want to read the code or run
your own copy, the LICENSE file is the contract that matters; the ToS is the
contract that matters when you use the public site.
