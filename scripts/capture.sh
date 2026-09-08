#!/usr/bin/env bash
# Capture one area (or all of them), in the only order that produces stable output.
#
#   SHOWCASE_PASSWORD='...' ./scripts/capture.sh iam
#   SHOWCASE_PASSWORD='...' ./scripts/capture.sh            # every manifest
#
# The ordering is the whole point of this wrapper:
#
#   1. authenticate  — writes last_login on the user row
#   2. freeze        — pins last_login and created_at to fixture time
#   3. capture       — reuses the token from step 1, so nothing writes a timestamp again
#
# Doing the login inside the capture instead leaves a wall-clock value in the IAM users
# screenshot, and that file then differs on every run for a reason that has nothing to do with
# the UI. Measured: 6 of 8 files byte-identical across two runs, the two users screens not.
set -euo pipefail

cd "$(dirname "$0")/.."

: "${SHOWCASE_PASSWORD:?set SHOWCASE_PASSWORD}"
IDENTITY="${SHOWCASE_IDENTITY:-demo@fleetbase.io}"
BASE_URL="${BASE_URL:-http://localhost}"

AREAS=("$@")
if [[ ${#AREAS[@]} -eq 0 ]]; then
    AREAS=()
    for m in capture/manifests/*.mjs; do
        AREAS+=("$(basename "$m" .mjs)")
    done
fi

echo "==> Authenticating as ${IDENTITY}"
TOKEN="$(curl -fsS -X POST "${BASE_URL}/int/v1/auth/login" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"identity\":\"${IDENTITY}\",\"password\":\"${SHOWCASE_PASSWORD}\"}" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))')"

if [[ -z "$TOKEN" ]]; then
    echo "::error::Could not authenticate as ${IDENTITY}. Has the organization been seeded?"
    exit 1
fi
echo "::add-mask::${TOKEN}"

echo "==> Freezing server-side timestamps"
CODE="$(base64 -i scripts/freeze-timestamps.php | tr -d '\n')"
OUT="$(docker compose exec -T \
    -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Demo}" \
    application php artisan tinker --execute="eval(base64_decode('${CODE}'));" 2>&1 || true)"
printf '%s\n' "$OUT" | grep -E '^(FROZEN_|FREEZE_ERROR)' || true
if printf '%s\n' "$OUT" | grep -q 'FREEZE_ERROR'; then
    echo "::error::Could not freeze timestamps; captures will not be reproducible."
    exit 1
fi

for area in "${AREAS[@]}"; do
    manifest="manifests/${area}.mjs"
    if [[ ! -f "capture/${manifest}" ]]; then
        echo "::error::no manifest at capture/${manifest}"
        exit 1
    fi

    echo
    echo "==> Capturing ${area}"
    # Exported rather than prefixed onto the command: BASE_URL and API_HOST are the same value
    # here (one origin serves both roles), and a `API_HOST="$BASE_URL"` prefix would expand
    # BASE_URL from the *outer* shell before the prefix assignment applied — correct today,
    # but only by accident.
    export BASE_URL
    export API_HOST="$BASE_URL"
    export SHOWCASE_TOKEN="$TOKEN"
    SEEDED_FIXTURES="$(cat .seeded-fixtures.json 2>/dev/null || true)"
    export SEEDED_FIXTURES

    (
        cd capture
        npm run --silent capture -- --manifest "$manifest" --out "./out/${area}"
    )
done

echo
echo "✔ Captured: ${AREAS[*]}"
