#!/usr/bin/env bash
# Regenerate the 8 og:image SVGs + their PNG renders.
# PNGs in public/og/ are what we serve to crawlers; SVGs here are the source.
#
# Run from anywhere:
#   bash public/og/src/build.sh
#
# Requires ImageMagick (`convert` on PATH).

set -e
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
OG_DIR="$(cd -- "$SCRIPT_DIR/.." &> /dev/null && pwd)"
SRC="$OG_DIR/src"

emit_svg() {
    local name="$1" t1="$2" t2="$3" sub="$4" path="$5"
    cat > "$SRC/$name.svg" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630">
  <rect width="1200" height="630" fill="#0f172a"/>
  <defs>
    <pattern id="g" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
      <circle cx="1.2" cy="1.2" r="1" fill="#1e293b"/>
    </pattern>
  </defs>
  <rect width="1200" height="630" fill="url(#g)"/>
  <rect x="1145" y="0" width="55" height="630" fill="#7a1e1e"/>
  <rect x="60" y="58" width="44" height="44" fill="#7a1e1e" rx="6"/>
  <text x="82" y="89" font-family="Georgia, serif" font-size="22" font-weight="bold" fill="#ffffff" text-anchor="middle">CT</text>
  <text x="120" y="89" font-family="Helvetica, Arial, sans-serif" font-size="18" font-weight="500" fill="#cbd5e1" letter-spacing="3">CYBER · TRACKR</text>
  <line x1="60" y1="125" x2="200" y2="125" stroke="#7a1e1e" stroke-width="2"/>
  <text x="60" y="305" font-family="Georgia, serif" font-size="76" font-weight="500" fill="#ffffff">$t1</text>
  $( [ -n "$t2" ] && echo "<text x=\"60\" y=\"385\" font-family=\"Georgia, serif\" font-size=\"76\" font-weight=\"500\" font-style=\"italic\" fill=\"#ffffff\">$t2</text>" )
  <text x="60" y="$( [ -n "$t2" ] && echo 460 || echo 380 )" font-family="Helvetica, Arial, sans-serif" font-size="30" font-weight="400" fill="#94a3b8">$sub</text>
  <text x="60" y="570" font-family="Courier, monospace" font-size="22" fill="#64748b">cyber.trackr.live$path</text>
</svg>
EOF
}

emit_svg home      "The reference desk"     "for cyber compliance."   "STIGs · 800-53 · CCIs · SCAP — searchable, cross-linked, free."  "/"
emit_svg stig      "Security Technical"     "Implementation Guides"   "Every DISA STIG, comparable across versions."                     "/stig"
emit_svg scap      "SCAP Benchmarks"        ""                        "Automated scanning content from DISA, browseable + diff-able."    "/scap"
emit_svg cci       "Common Control"         "Identifiers."            "The atomic compliance statements behind every STIG rule."         "/cci"
emit_svg rmf       "NIST 800-53"            "Security Controls."      "Rev 4 + Rev 5, cross-linked to STIGs, CCIs, and baselines."       "/rmf/5"
emit_svg baselines "Baseline coverage"      "heat map."               "20 families × 4 NIST baselines, at a glance."                     "/baselines"
emit_svg plans     "RMF Plan Generator."    ""                        "20 families. Schema-driven. Word + JSON output. No accounts."     "/plans"
emit_svg ckl       "CKL / CKLB Viewer."     ""                        "Browser-only checklist editor with SCAP overlay + CKLB export."   "/ckl-viewer"

for s in home stig scap cci rmf baselines plans ckl; do
    convert -density 144 -resize 1200x630! -background none "$SRC/$s.svg" "$OG_DIR/$s.png"
    echo "$(printf '%-12s' "$s") — $(stat -c%s "$OG_DIR/$s.png" 2>/dev/null || stat -f%z "$OG_DIR/$s.png") bytes"
done
