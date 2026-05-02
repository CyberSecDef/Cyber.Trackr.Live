#!/usr/bin/env bash
# One-shot bulk submission of every site URL to IndexNow.
#
# Pulls https://cyber.trackr.live/sitemap.xml, extracts every <loc>,
# auto-discovers the IndexNow key file in /public/, and POSTs the whole
# lot to the shared IndexNow endpoint at api.indexnow.org. That endpoint
# fans out to every participating search engine — Bing, Yandex, Seznam,
# Naver, DuckDuckGo (which uses Bing's index) — in one call.
#
# Run weekly from prod (or anywhere with curl + php on PATH):
#
#   ./bin/submit-urls.sh
#
# IndexNow's protocol limit is 10,000 URLs per POST. The script batches
# automatically if the sitemap ever grows past that — current sitemap
# is ~4,400 URLs and fits in a single request.
#
# Exit codes:
#   0 = every batch returned 2xx
#   1 = setup failure (no key file, no curl, sitemap unreachable, …)
#   2 = at least one batch returned non-2xx (the POST went out but
#       IndexNow rejected something)

set -euo pipefail
cd "$(dirname "$0")/.."

HOST="cyber.trackr.live"
SITEMAP_URL="https://${HOST}/sitemap.xml"
ENDPOINT="https://api.indexnow.org/IndexNow"
BATCH_LIMIT=10000

echo "──────────────────────────────────────────"
echo "  IndexNow bulk submit · ${HOST}"
echo "  $(date -Iseconds)"
echo "──────────────────────────────────────────"

# ── 1. Discover the IndexNow key file ───────────────────────────────
# Same pattern as App\Service\IndexNowPinger — first /public/<hex>.txt
# whose contents match the filename stem wins.
KEY_PATH=""
for f in public/*.txt; do
    [ -f "$f" ] || continue
    name=$(basename "$f" .txt)
    if [[ "$name" =~ ^[a-fA-F0-9]{32,128}$ ]]; then
        body=$(tr -d '[:space:]' < "$f")
        if [ "$body" = "$name" ]; then
            KEY_PATH="$f"
            break
        fi
    fi
done
if [ -z "$KEY_PATH" ]; then
    echo "ERROR: No IndexNow key file in public/ (looking for /public/<hex>.txt)" >&2
    exit 1
fi
KEY=$(basename "$KEY_PATH" .txt)
KEY_LOCATION="https://${HOST}/$(basename "$KEY_PATH")"
echo "  key:         ${KEY:0:8}…${KEY: -4}"
echo "  keyLocation: ${KEY_LOCATION}"

# ── 2. Fetch the sitemap, extract URLs ──────────────────────────────
echo
echo "Fetching sitemap from ${SITEMAP_URL} …"
URLS=$(curl -fsS "${SITEMAP_URL}" | grep -oE '<loc>[^<]+' | sed 's|<loc>||')
URL_COUNT=$(echo "${URLS}" | grep -c .)
if [ "${URL_COUNT}" -eq 0 ]; then
    echo "ERROR: sitemap returned zero URLs" >&2
    exit 1
fi
echo "  ${URL_COUNT} URLs extracted."

# ── 3. POST in batches of BATCH_LIMIT ───────────────────────────────
# JSON encoding is done in PHP (which is guaranteed on this host) so we
# don't pick up a jq dependency.
TMP_DIR=$(mktemp -d)
trap "rm -rf ${TMP_DIR}" EXIT

echo "${URLS}" > "${TMP_DIR}/urls.txt"
split -l "${BATCH_LIMIT}" "${TMP_DIR}/urls.txt" "${TMP_DIR}/batch_"

BATCH_NUM=0
TOTAL_BATCHES=$(ls "${TMP_DIR}"/batch_* | wc -l)
ANY_FAILURE=0

for batch in "${TMP_DIR}"/batch_*; do
    BATCH_NUM=$((BATCH_NUM + 1))
    BATCH_SIZE=$(wc -l < "${batch}")

    # Build the JSON payload. PHP's json_encode handles whitespace/escape
    # corner cases that a hand-rolled bash builder would get wrong.
    php -r '
        $urls = array_values(array_filter(array_map("trim", file($argv[1]))));
        echo json_encode([
            "host"        => $argv[2],
            "key"         => $argv[3],
            "keyLocation" => $argv[4],
            "urlList"     => $urls,
        ], JSON_UNESCAPED_SLASHES);
    ' "${batch}" "${HOST}" "${KEY}" "${KEY_LOCATION}" > "${batch}.json"

    echo
    echo "[${BATCH_NUM}/${TOTAL_BATCHES}] POST ${BATCH_SIZE} URLs to ${ENDPOINT} …"
    HTTP_CODE=$(curl -sS -o "${batch}.response" -w "%{http_code}" \
        -X POST \
        -H "Content-Type: application/json; charset=utf-8" \
        -H "User-Agent: Cyber-Trackr/IndexNow-bulk" \
        --data-binary "@${batch}.json" \
        "${ENDPOINT}")

    if [[ "${HTTP_CODE}" =~ ^2 ]]; then
        echo "  HTTP ${HTTP_CODE} — accepted."
    else
        ANY_FAILURE=1
        echo "  HTTP ${HTTP_CODE} — rejected." >&2
        echo "  Response: $(head -c 400 "${batch}.response")" >&2
    fi
done

echo
echo "──────────────────────────────────────────"
if [ "${ANY_FAILURE}" -eq 0 ]; then
    echo "  Submitted ${URL_COUNT} URLs across ${TOTAL_BATCHES} batch(es). All accepted."
    exit 0
else
    echo "  Submitted ${URL_COUNT} URLs across ${TOTAL_BATCHES} batch(es). Some rejected — see output above."
    exit 2
fi
