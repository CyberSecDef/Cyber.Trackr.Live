#!/usr/bin/env bash
# Reset permissions across the Symfony app to the standard PHP-site pattern:
#
#   directories  → 755   (owner: rwx, group + others: r-x)
#   files        → 644   (owner: rw,  group + others: r--)
#   bin scripts  → 755   (need exec bit so cron / deploy can run them)
#   .env         → 600   (owner-only read; if it has secrets they don't
#                          leak through a misconfigured web server)
#
# Run after rsync if you suspect perms got mangled, or any time prod
# starts returning 403/500 from "permission denied" on the cache or
# template files.
#
#   ./bin/fix-perms.sh
#
# Safe to re-run. Idempotent.

set -euo pipefail
cd "$(dirname "$0")/.."

echo "──────────────────────────────────────────"
echo "  Fixing PHP-site permissions"
echo "  $(pwd)"
echo "──────────────────────────────────────────"

echo
echo "[1/4] Directories → 755 …"
find . -type d -exec chmod 755 {} \;

echo
echo "[2/4] Files → 644 …"
find . -type f -exec chmod 644 {} \;

echo
echo "[3/4] Restoring exec bit on bin/ scripts …"
chmod 755 bin/console bin/phpunit 2>/dev/null || true
chmod 755 bin/*.sh 2>/dev/null || true

echo
echo "[4/4] Locking down secrets (.env → 600) …"
# Tighten .env files so only the owner can read them. The 2>/dev/null
# || true guards keep the script idempotent on machines where some of
# these don't exist (dev usually has .env.local but not .env, etc.).
chmod 600 .env             2>/dev/null || true
chmod 600 .env.local       2>/dev/null || true
chmod 600 .env.prod.local  2>/dev/null || true
chmod 600 .env.dev.local   2>/dev/null || true

echo
echo "──────────────────────────────────────────"
echo "  Permissions reset."
echo "──────────────────────────────────────────"
