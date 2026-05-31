#!/bin/sh
set -e

# var/ is the only writable path (cache + CSRF-session files). When it's a
# mounted volume it starts empty, so (re)create and chown it on every boot.
mkdir -p var/cache var/log
chown -R www-data:www-data var

# Symfony prod requires APP_SECRET. If the operator didn't pin one, generate a
# throwaway value — sessions here are CSRF-only and stateless, so a fresh
# secret per container start is fine.
if [ -z "${APP_SECRET:-}" ]; then
    APP_SECRET="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    export APP_SECRET
fi

exec "$@"
