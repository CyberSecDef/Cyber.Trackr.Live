#!/usr/bin/env python3
"""
Convert the parsed CNSSI 1253 baseline data into OSCAL Profile JSON
files compatible with App\\Service\\OverlayLoader.

CNSSI 1253 publishes per-axis baselines (C-low, C-mod, C-high, I-low,
... A-high). For the /rmf/5 chip strip we roll those up to the
high-water-mark interpretation (CNSS Low = control required at *any*
leg's low level), which mirrors the NIST L/M/H model the user already
sees on the page.

Per-axis data is preserved in resources/data/overlays/cnssi-1253-axes.json
for future use (a possible "advanced" toggle that exposes 9 axis chips
instead of 3 rolled-up ones).
"""

"""
Usage:
    python3 build_cnssi_oscal.py [PARSED_JSON] [OUT_DIR]

Defaults to /tmp/cnss_extract/controls.json (the output of
parse_cnssi_pdf.py) and /tmp/cnss_extract/oscal/ for the four
CNSSI_1253_*-baseline_profile.json files. Override both args to
write directly into ../  (the parent overlays/ directory).
"""

import json
import sys
import uuid
import datetime
from pathlib import Path

PARSED  = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/cnss_extract/controls.json")
OUT_DIR = Path(sys.argv[2] if len(sys.argv) > 2 else "/tmp/cnss_extract/oscal")
NAMESPACE = uuid.UUID("8a4cf6dc-4f74-4a3e-b3a6-1cef47ed7ff9")  # arbitrary stable ns
TODAY    = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")

# CNSSI 1253 (08/01/2022) is the source revision. Bump if a new edition lands.
SOURCE_REV  = "CNSSI 1253 (29 July 2022)"
SOURCE_URL  = "https://www.cnss.gov/CNSS/issuances/Instructions.cfm"
CATALOG_REF = "https://raw.githubusercontent.com/usnistgov/oscal-content/main/nist.gov/SP800-53/rev5/json/NIST_SP-800-53_rev5_catalog.json"


def baseline_profile(level: str, control_ids: list[str], description: str) -> dict:
    """Build one OSCAL Profile document for the given baseline level."""
    title = f"CNSSI 1253 {level.upper()} Impact Baseline (high-water-mark across CIA)"
    catalog_uuid = str(uuid.uuid5(NAMESPACE, "nist-800-53-r5-catalog"))
    profile_uuid = str(uuid.uuid5(NAMESPACE, f"cnssi-1253-{level}"))

    return {
        "profile": {
            "uuid": profile_uuid,
            "metadata": {
                "title":         title,
                "version":       "2022-07-29",
                "oscal-version": "1.1.2",
                "last-modified": TODAY,
                "remarks":       description,
            },
            "imports": [{
                "href":             f"#{catalog_uuid}",
                "include-controls": [{
                    "with-ids": sorted(control_ids),
                }],
            }],
            "merge":      {"as-is": True},
            "back-matter": {
                "resources": [{
                    "uuid":        catalog_uuid,
                    "description": "NIST SP 800-53 Revision 5 catalog (the controls this profile selects from).",
                    "rlinks":      [{"href": CATALOG_REF, "media-type": "application/oscal.catalog+json"}],
                }, {
                    "uuid":        str(uuid.uuid5(NAMESPACE, "cnssi-1253-source")),
                    "description": SOURCE_REV,
                    "rlinks":      [{"href": SOURCE_URL}],
                }],
            },
        },
    }


def main() -> None:
    parsed = json.loads(PARSED.read_text())
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    # High-water-mark roll-ups: a control belongs to CNSS-<level> if it
    # appears in *any* of (C-<level>, I-<level>, A-<level>).
    rollups = {
        "low":      [c for c, info in parsed.items() if any([info["c"][0], info["i"][0], info["a"][0]])],
        "moderate": [c for c, info in parsed.items() if any([info["c"][1], info["i"][1], info["a"][1]])],
        "high":     [c for c, info in parsed.items() if any([info["c"][2], info["i"][2], info["a"][2]])],
        "privacy":  [c for c, info in parsed.items() if info["privacy"]],
    }

    descriptions = {
        "low":      "Controls required for a National Security System where any CIA leg is rated LOW impact. Sourced from CNSSI 1253 Tables D-1 through D-20, rolled up to the highest-water-mark interpretation across confidentiality, integrity, and availability axes. The per-axis baselines (C-low, I-low, A-low) are preserved separately in cnssi-1253-axes.json.",
        "moderate": "Controls required for a National Security System where any CIA leg is rated MODERATE impact. High-water-mark roll-up of CNSSI 1253 per-axis baselines (C-moderate, I-moderate, A-moderate).",
        "high":     "Controls required for a National Security System where any CIA leg is rated HIGH impact. High-water-mark roll-up of CNSSI 1253 per-axis baselines (C-high, I-high, A-high).",
        "privacy":  "Controls in the CNSSI 1253 Privacy Control Baseline. Distinct from the C/I/A impact baselines — applies to NSS that process PII.",
    }

    for level, ids in rollups.items():
        doc = baseline_profile(level, ids, descriptions[level])
        out = OUT_DIR / f"CNSSI_1253_{level.upper()}-baseline_profile.json"
        out.write_text(json.dumps(doc, indent=2))
        print(f"  wrote {out.name:<55} {len(ids):>4} controls")

    # Per-axis fidelity preserved as a sidecar JSON. Not an OSCAL Profile;
    # OverlayLoader ignores it (we only `name *_profile.json`). Useful if
    # you later want to expose 9 axis chips on top of the 3 rolled-up ones.
    axes = {f"cnss-{axis}-{lvl}":
            [c for c, info in parsed.items() if info[axis][i]]
            for axis in ("c", "i", "a")
            for i, lvl in enumerate(("low", "moderate", "high"))}
    (OUT_DIR / "cnssi-1253-axes.json").write_text(json.dumps(axes, indent=2))
    print()
    print("Per-axis baseline counts (preserved in cnssi-1253-axes.json):")
    for k, v in axes.items():
        print(f"  {k:<22} {len(v):>4}")


if __name__ == "__main__":
    main()
