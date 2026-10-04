#!/usr/bin/env bash
# Bring the stack up at a given release tag.
#
#   RELEASE_TAG=v0.7.57 ./scripts/up.sh
#
# Writes ./.env (which Compose loads automatically) and pulls before starting, so the
# "am I running the right build?" question has one answer and it is in one file.
set -euo pipefail

cd "$(dirname "$0")/.."

: "${RELEASE_TAG:?set RELEASE_TAG, e.g. RELEASE_TAG=v0.7.57 ./scripts/up.sh}"

# Reuse the existing key if there is one. Regenerating it on every run would invalidate every
# encrypted column already in the database, which turns a re-provision into a confusing pile
# of decryption errors rather than a clean reseed.
if [[ -f .env ]] && grep -q '^APP_KEY=' .env; then
    APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)"
else
    APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
fi

# This file is rewritten wholesale on every run, so anything a developer needs to persist has
# to be carried through explicitly. Precedence: the environment, then whatever the previous
# .env said, then the default. Silently dropping a port override here costs ten confusing
# minutes staring at "port is already allocated" from a stack that started fine yesterday.
carry() {
    local key="$1" fallback="$2" from_env="${!1:-}"
    if [[ -n "$from_env" ]]; then
        printf '%s\n' "$from_env"
    elif [[ -f .env ]] && grep -q "^${key}=" .env; then
        grep "^${key}=" .env | cut -d= -f2-
    else
        printf '%s\n' "$fallback"
    fi
}

# 80 is the only value that works for a real capture — the published console derives its API
# host from window.location and drops the port, so the API has to be reachable on the default
# port. Overridable only so the stack can be brought up alongside something else for
# inspection; captures against a non-80 port will fail to load data.
HTTP_PORT="$(carry HTTP_PORT 80)"
# Likewise 38000 is compiled into the console for the socket connection. A developer whose own
# Fleetbase dev stack already owns it can remap, at the cost of the realtime screens.
WSS_PORT="$(carry WSS_PORT 38000)"
# Optional; enables the Google Maps capture pass (scripts/capture.sh). Carried like the ports
# so a key given once survives the next up.sh.
GOOGLE_MAPS_API_KEY="$(carry GOOGLE_MAPS_API_KEY "")"

cat > .env <<ENV
# Written by scripts/up.sh — not committed.
RELEASE_TAG=${RELEASE_TAG}
APP_KEY=${APP_KEY}
HTTP_PORT=${HTTP_PORT}
WSS_PORT=${WSS_PORT}
GOOGLE_MAPS_API_KEY=${GOOGLE_MAPS_API_KEY}
ENV

if [[ "$HTTP_PORT" != "80" ]]; then
    echo "::warning::HTTP_PORT is ${HTTP_PORT}, not 80. The console resolves its API host from"
    echo "           window.location without the port, so captures will not load data."
fi

echo "==> Pulling ${RELEASE_TAG}"
docker compose pull --quiet

echo "==> Starting"
# --wait blocks on the healthchecks, so provision.sh can assume the database is actually
# accepting connections rather than racing it.
docker compose up -d --wait

echo
echo "✔ Stack up at ${RELEASE_TAG}. Next: ./scripts/provision.sh"
