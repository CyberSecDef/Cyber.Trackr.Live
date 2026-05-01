#!/usr/bin/env python3
"""
Parse CNSSI 1253 baseline tables out of the PDF and emit a structured
JSON file mapping each 800-53 r5 control to its baseline membership.

Output schema:
{
  "<control-id>": {
    "title": "...",
    "privacy": true|false,
    "c": [low?, mod?, high?],   # booleans
    "i": [low?, mod?, high?],
    "a": [low?, mod?, high?]
  },
  ...
}

Usage:
    python3 parse_cnssi_pdf.py [PDF_PATH] [OUT_DIR]

Defaults assume the original developer's local layout. Override both
args when re-running for a new CNSS revision; then feed the resulting
controls.json into build_cnssi_oscal.py to regenerate the four
CNSSI_1253_*-baseline_profile.json files in the parent directory.

Requires: pdftotext (poppler-utils) on PATH. No Python dependencies
beyond the standard library.

Strategy: pdftotext -layout per page; on each page locate the
"L  M  H  L  M  H  L  M  H" header to learn the 9 column positions for
that page; then for each line that begins with a control ID, sample the
character at each of those 9 positions (±1 for slop) and look for X or +.
Privacy column lives somewhere between the title and the first L; we
scan that range for X/+ too.
"""

import re
import json
import subprocess
import sys
from pathlib import Path

PDF     = Path(sys.argv[1] if len(sys.argv) > 1 else "/home/rweber/Documents/cnss1253/control_selection.pdf")
OUT_DIR = Path(sys.argv[2] if len(sys.argv) > 2 else "/tmp/cnss_extract")

# Pages where the baseline tables live (Appendix D). Wider than necessary;
# the parser ignores pages with no header line. AC-1 is on page 25 in
# the 08/2022 revision.
START_PAGE = 21
END_PAGE   = 110

# Symbols indicating the control IS in the NSS baseline for that
# C/I/A-impact level cell. Per the legend on page 23:
#   "X"  — inherited from a NIST 800-53B baseline
#   "+"  — added by CNSS specifically for NSS
#   "--" — explicit deselection (control IS in NIST baseline but NOT NSS)
#   blank — not in baseline
POSITIVE_MARKS = ("X", "x", "+")

CTRL_RE   = re.compile(r"^([A-Z]{2})-(\d+)(?:\((\d+)\))?\s+(.*?)$")
HEADER_RE = re.compile(r"^(\s+L\s+M\s+H\s+L\s+M\s+H\s+L\s+M\s+H)\s*$")


def pdftotext_page(p: int) -> str:
    return subprocess.check_output(
        ["pdftotext", "-layout", "-f", str(p), "-l", str(p), str(PDF), "-"],
        text=True,
    )


def column_positions(header: str) -> list[int]:
    """Return the 9 character-column indices of L, M, H, L, M, H, L, M, H."""
    return [m.start() for m in re.finditer(r"[LMH]", header)]


def has_mark_at(line: str, col: int) -> bool:
    """True if a positive baseline mark sits within ±1 of `col`."""
    for offset in (0, -1, 1):
        c = col + offset
        if 0 <= c < len(line) and line[c] in POSITIVE_MARKS:
            return True
    return False


def has_privacy_mark(line: str, first_lmh_col: int) -> bool:
    """The Privacy Control Baseline column lives in the title area,
    before the first L of the C/I/A grid. Same X/+ semantics; '--' is
    explicit deselection."""
    band = line[25:max(25, first_lmh_col - 2)]
    if "--" in band:
        return False
    return any(m in band for m in POSITIVE_MARKS)


def parse_page(p: int) -> dict[str, dict]:
    text = pdftotext_page(p)
    lines = text.splitlines()

    # Locate the L M H header line on this page.
    header_idx = None
    for i, line in enumerate(lines):
        if HEADER_RE.match(line):
            header_idx = i
            break
    if header_idx is None:
        return {}

    cols = column_positions(lines[header_idx])
    if len(cols) != 9:
        return {}

    out: dict[str, dict] = {}
    # Walk every line *after* the header that looks like a control row.
    for line in lines[header_idx + 1 :]:
        m = CTRL_RE.match(line.rstrip())
        if not m:
            continue
        family, num, enh, rest = m.groups()
        cid = f"{family}-{num}" + (f".{enh}" if enh else "")
        cid_lower = cid.lower()

        # The "title" portion is whatever's between the id and the first
        # marker (X, +, or --). Title-only rows appear when a control has
        # no baseline membership at all.
        title = rest
        for marker in ("X", "+", "--"):
            title = title.split(marker)[0]
        title = title.strip()

        marks = [has_mark_at(line, c) for c in cols]
        privacy = has_privacy_mark(line, cols[0])

        out[cid_lower] = {
            "title":   title,
            "privacy": privacy,
            "c":       marks[0:3],
            "i":       marks[3:6],
            "a":       marks[6:9],
        }
    return out


def main() -> None:
    all_controls: dict[str, dict] = {}
    for p in range(START_PAGE, END_PAGE + 1):
        page_data = parse_page(p)
        # Later pages may revisit the same control (rare), but we keep first.
        for cid, info in page_data.items():
            if cid not in all_controls:
                all_controls[cid] = info

    # Also write a per-baseline summary so we can verify counts before
    # building OSCAL profiles.
    summary = {
        "cnss-c-low":     [c for c, i in all_controls.items() if i["c"][0]],
        "cnss-c-moderate":[c for c, i in all_controls.items() if i["c"][1]],
        "cnss-c-high":    [c for c, i in all_controls.items() if i["c"][2]],
        "cnss-i-low":     [c for c, i in all_controls.items() if i["i"][0]],
        "cnss-i-moderate":[c for c, i in all_controls.items() if i["i"][1]],
        "cnss-i-high":    [c for c, i in all_controls.items() if i["i"][2]],
        "cnss-a-low":     [c for c, i in all_controls.items() if i["a"][0]],
        "cnss-a-moderate":[c for c, i in all_controls.items() if i["a"][1]],
        "cnss-a-high":    [c for c, i in all_controls.items() if i["a"][2]],
        "cnss-privacy":   [c for c, i in all_controls.items() if i["privacy"]],
    }

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUT_DIR / "controls.json").write_text(json.dumps(all_controls, indent=2))
    (OUT_DIR / "baselines.json").write_text(json.dumps(summary, indent=2))

    print(f"Parsed {len(all_controls)} unique controls.")
    print()
    print("Baseline counts:")
    for k, v in summary.items():
        print(f"  {k:<20} {len(v):>4} controls")
    print()
    print("=== sanity checks ===")
    for cid in ["ac-1", "ac-2", "ac-2.1", "au-2", "pt-2", "ac-3.14"]:
        info = all_controls.get(cid, "MISSING")
        print(f"  {cid:<10} {info}")


if __name__ == "__main__":
    main()
