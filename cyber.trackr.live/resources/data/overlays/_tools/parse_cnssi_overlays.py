#!/usr/bin/env python3
"""
Parse CNSSI 1253 attachment overlays (Classified System Overlay,
Space Platform Overlay) and emit OSCAL Profile JSONs.

Unlike the baseline tables in the main CNSSI 1253 PDF (which use a
gridded X/+ format), the overlay PDFs use a prose-paragraph format:

  Classified (additive overlay, 104 controls — every entry is selected):
      AC-1, (Access Control) Policy and Procedures
              Justification to Select: ...
              Reference(s): ...

  Space (additive *and* subtractive, applied on top of CNSS HHH):
      (U) CP-12, SAFE MODE
      (U) Justification to Select:
      (U) Space Platform Guidance: ...

      (U) CP-9, SYSTEM BACKUP
      (U) Justification to Not Select: Assumption - NOT GENERAL PURPOSE

For Space, the *effective* baseline is computed as:
      CNSS HHH ∪ space_select  −  space_not_select

That matches what an authorizing official would actually implement, and
keeps the chip semantics consistent with the existing baselines (the
chip means "controls in this baseline", not "controls this overlay
discusses").

Usage:
    python3 parse_cnssi_overlays.py [CLASSIFIED_PDF] [SPACE_PDF] [OUT_DIR]

Defaults assume the original developer's local layout. Override args
when re-running for a new revision.
"""

import json
import re
import subprocess
import sys
import uuid
import datetime
from pathlib import Path

CLASSIFIED_PDF = Path(sys.argv[1] if len(sys.argv) > 1 else "/home/rweber/Documents/cnss1253/classified.pdf")
SPACE_PDF      = Path(sys.argv[2] if len(sys.argv) > 2 else "/home/rweber/Documents/cnss1253/space.pdf")
OUT_DIR        = Path(sys.argv[3] if len(sys.argv) > 3 else "/tmp/cnss_extract/oscal")

# Mirror the namespace used by build_cnssi_oscal.py for stable UUIDs.
NAMESPACE = uuid.UUID("8a4cf6dc-4f74-4a3e-b3a6-1cef47ed7ff9")
TODAY     = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")

CATALOG_REF = "https://raw.githubusercontent.com/usnistgov/oscal-content/main/nist.gov/SP800-53/rev5/json/NIST_SP-800-53_rev5_catalog.json"

# CNSS HHH baseline lives next to this script's parent dir.
CNSS_HIGH_FILE = Path(__file__).resolve().parent.parent / "CNSSI_1253_HIGH-baseline_profile.json"


# Match a control header line. Both styles are accepted:
#   AC-1, ...
#   (U) AC-1, ...
CTRL_HEADER_RE = re.compile(
    r"^(?:\(U\)\s+)?([A-Z]{2})-(\d+)(?:\((\d+)\))?,\s*(.+?)$"
)


def to_oscal_id(family: str, num: str, enh: str | None) -> str:
    base = f"{family}-{num}".lower()
    return f"{base}.{enh}" if enh else base


def parse_classified(pdf: Path) -> list[str]:
    """Every header line in the Classified overlay represents a control
    selected by the overlay (all-additive). Returns OSCAL ids."""
    text = subprocess.check_output(["pdftotext", str(pdf), "-"], text=True)
    out: list[str] = []
    for line in text.splitlines():
        m = CTRL_HEADER_RE.match(line.strip())
        if m:
            out.append(to_oscal_id(m.group(1), m.group(2), m.group(3)))
    # De-dupe in case a control appears more than once across pages.
    seen = set()
    return [c for c in out if not (c in seen or seen.add(c))]


def parse_space(pdf: Path) -> tuple[list[str], list[str]]:
    """The Space overlay has both selections and deselections. Returns
    (selected_ids, not_selected_ids).

    A header is followed (within the next ~6 lines) by either
    'Justification to Select' or 'Justification to Not Select'. The
    'Not Select' check must come first because it's a strict superset
    string match."""
    text = subprocess.check_output(["pdftotext", str(pdf), "-"], text=True)
    lines = text.splitlines()
    selected, not_selected = [], []
    seen = set()

    for i, raw in enumerate(lines):
        m = CTRL_HEADER_RE.match(raw.strip())
        if not m:
            continue
        cid = to_oscal_id(m.group(1), m.group(2), m.group(3))
        if cid in seen:
            continue
        # Look ahead a small window for the action verdict.
        window = " ".join(lines[i + 1 : i + 8])
        if "Justification to Not Select" in window:
            not_selected.append(cid)
            seen.add(cid)
        elif "Justification to Select" in window:
            selected.append(cid)
            seen.add(cid)
        # else: header with no nearby verdict line — ignore (stray match)

    return selected, not_selected


def build_profile(level: str, control_ids: list[str], title: str, remarks: str) -> dict:
    catalog_uuid = str(uuid.uuid5(NAMESPACE, "nist-800-53-r5-catalog"))
    profile_uuid = str(uuid.uuid5(NAMESPACE, f"cnssi-1253-{level}"))
    return {
        "profile": {
            "uuid": profile_uuid,
            "metadata": {
                "title":         title,
                "version":       "2022-2025",
                "oscal-version": "1.1.2",
                "last-modified": TODAY,
                "remarks":       remarks,
            },
            "imports": [{
                "href": f"#{catalog_uuid}",
                "include-controls": [{"with-ids": sorted(control_ids)}],
            }],
            "merge":      {"as-is": True},
            "back-matter": {
                "resources": [{
                    "uuid":        catalog_uuid,
                    "description": "NIST SP 800-53 Revision 5 catalog (the controls this profile selects from).",
                    "rlinks":      [{"href": CATALOG_REF, "media-type": "application/oscal.catalog+json"}],
                }],
            },
        },
    }


def main() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    # --- Classified: pure additive --------------------------------------
    classified_ids = parse_classified(CLASSIFIED_PDF)
    print(f"Classified overlay: {len(classified_ids)} controls (all selected)")
    print(f"  sample: {classified_ids[:6]}")

    classified_doc = build_profile(
        "classified",
        classified_ids,
        "CNSSI 1253 Attachment 5 — Classified System Overlay (additive on top of any baseline)",
        "Controls identified by the CNSSI 1253 Classified System Overlay (09/30/2022) as required to safeguard classified information stored, processed, or transmitted by NSS. Baseline-independent — applies on top of whichever NSS baseline (Low / Moderate / High / Privacy) the system uses.",
    )
    (OUT_DIR / "CNSSI_1253_CLASSIFIED-overlay_profile.json").write_text(
        json.dumps(classified_doc, indent=2)
    )

    # --- Space: tailoring of CNSS HHH -----------------------------------
    space_select, space_not_select = parse_space(SPACE_PDF)
    print(f"\nSpace overlay raw counts:")
    print(f"  selected:     {len(space_select)}")
    print(f"  not selected: {len(space_not_select)}")
    print(f"  sample sel:   {space_select[:5]}")
    print(f"  sample !sel:  {space_not_select[:5]}")

    if not CNSS_HIGH_FILE.exists():
        sys.exit(f"ERROR: {CNSS_HIGH_FILE} not found — run build_cnssi_oscal.py first")
    high_baseline = set(
        json.loads(CNSS_HIGH_FILE.read_text())
        ["profile"]["imports"][0]["include-controls"][0]["with-ids"]
    )
    space_effective = sorted((high_baseline | set(space_select)) - set(space_not_select))
    print(f"\nSpace effective baseline = CNSS HHH ({len(high_baseline)}) "
          f"+ adds ({len(set(space_select) - high_baseline)}) "
          f"- removes ({len(set(space_not_select) & high_baseline)}) "
          f"= {len(space_effective)} controls")

    space_doc = build_profile(
        "space",
        space_effective,
        "CNSSI 1253 Attachment 2 — Space Platform Overlay (Aug 2025; CNSS HHH baseline tailored)",
        "Effective control baseline for a space NSS, derived by applying the Space Platform Overlay (August 2025) tailoring to the CNSSI 1253 High/High/High baseline: union of CNSS HHH plus controls the overlay adds, minus controls the overlay justifies as Not Selected (typically due to space-environment assumptions like NOT GENERAL PURPOSE or hard-radiation-only environments).",
    )
    (OUT_DIR / "CNSSI_1253_SPACE-overlay_profile.json").write_text(
        json.dumps(space_doc, indent=2)
    )

    # Sidecar with the raw select/not-select decisions for future use.
    (OUT_DIR / "cnssi-1253-overlay-decisions.json").write_text(json.dumps({
        "classified": {"selected": classified_ids},
        "space":      {"selected": space_select, "not_selected": space_not_select,
                       "effective": space_effective},
    }, indent=2))

    print(f"\nWrote profiles + sidecar to {OUT_DIR}/")


if __name__ == "__main__":
    main()
