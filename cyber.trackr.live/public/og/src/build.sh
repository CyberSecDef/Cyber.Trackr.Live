#!/usr/bin/env bash
# Regenerate the 8 og:image SVGs + their PNG renders.
# PNGs in public/og/ are what we serve to crawlers; SVGs here are the source.
#
# Run from anywhere:
#   bash public/og/src/build.sh
#
# Requires librsvg (`rsvg-convert` on PATH):
#
#   Debian/Ubuntu   sudo apt install librsvg2-bin
#   macOS           brew install librsvg
#
# Do NOT swap this back to ImageMagick's `convert`. When rsvg-convert is absent
# IM falls back to its own MSVG renderer, which silently mangles these cards:
# it drops the <line> rule under the wordmark, flattens the #0f172a ground and
# the dot pattern to pure black, and substitutes a sans face for the Georgia
# titles. No error, just a wrong card. The PNGs committed before 2026-09 were
# built that way; anything rendered here now supersedes them.

set -e

if ! command -v rsvg-convert > /dev/null 2>&1; then
    echo "build.sh: rsvg-convert not found on PATH — install librsvg2-bin (see header)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
OG_DIR="$(cd -- "$SCRIPT_DIR/.." &> /dev/null && pwd)"
SRC="$OG_DIR/src"

emit_svg() {
    local name="$1" t1="$2" t2="$3" sub="$4" path="$5"
    cat > "$SRC/$name.svg" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630">
  <!-- background + texture -->
  <rect width="1200" height="630" fill="#0f172a"/>
  <defs>
    <pattern id="g" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
      <circle cx="1.2" cy="1.2" r="1" fill="#1e293b"/>
    </pattern>
  </defs>
  <rect width="1200" height="630" fill="url(#g)"/>

  <!-- accent stripe (right) -->
  <rect x="1145" y="0" width="55" height="630" fill="#7a1e1e"/>

  <!-- brand seal + wordmark (top-left) -->
  <rect x="60" y="58" width="44" height="44" fill="#7a1e1e" rx="6"/>
  <text x="82" y="89" font-family="Georgia, serif" font-size="22" font-weight="bold" fill="#ffffff" text-anchor="middle">CT</text>
  <text x="120" y="89" font-family="Helvetica, Arial, sans-serif" font-size="18" font-weight="500" fill="#cbd5e1" letter-spacing="3">CYBER · TRACKR</text>

  <!-- thin rule under wordmark -->
  <line x1="60" y1="125" x2="200" y2="125" stroke="#7a1e1e" stroke-width="2"/>

  <!-- title -->
  <text x="60" y="305" font-family="Georgia, serif" font-size="76" font-weight="500" fill="#ffffff">$t1</text>
  $( [ -n "$t2" ] && echo "<text x=\"60\" y=\"385\" font-family=\"Georgia, serif\" font-size=\"76\" font-weight=\"500\" font-style=\"italic\" fill=\"#ffffff\">$t2</text>" )

  <!-- subtitle -->
  <text x="60" y="$( [ -n "$t2" ] && echo 460 || echo 380 )" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="400" fill="#94a3b8">$sub</text>

  <!-- url hint -->
  <text x="60" y="570" font-family="Courier, monospace" font-size="22" fill="#64748b">cyber.trackr.live$path</text>
</svg>
EOF
}

emit_svg home      "The reference desk"     "for cyber compliance."   "STIGs · 800-53 · CCIs · SCAP — searchable, cross-linked, free."  "/"
emit_svg stig      "Security Technical"     "Implementation Guides"   "Every DISA STIG, comparable across versions."                     "/stig"
emit_svg scap      "SCAP Benchmarks"        ""                        "Automated scanning content from DISA, browseable + diff-able."    "/scap"
emit_svg cci       "Control Correlation"    "Identifiers."            "The atomic compliance statements behind every STIG rule."         "/cci"
emit_svg rmf       "NIST 800-53"            "Security Controls."      "Rev 4 + Rev 5, cross-linked to STIGs, CCIs, and baselines."       "/rmf/5"
emit_svg baselines "Baseline coverage"      "heat map."               "20 families × 4 NIST baselines, at a glance."                     "/baselines"
emit_svg plans     "RMF Plan Generator."    ""                        "20 families. Schema-driven. Word + JSON output. No accounts."     "/plans"
emit_svg ckl       "CKL / CKLB Viewer."     ""                        "Browser-only checklist editor with SCAP overlay + CKLB export."   "/ckl-viewer"

for s in home stig scap cci rmf baselines plans ckl; do
    rsvg-convert --width 1200 --height 630 --output "$OG_DIR/$s.png" "$SRC/$s.svg"
    echo "$(printf '%-12s' "$s") — $(stat -c%s "$OG_DIR/$s.png" 2>/dev/null || stat -f%z "$OG_DIR/$s.png") bytes"
done
