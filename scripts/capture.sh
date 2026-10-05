#!/usr/bin/env bash
# Capture one area (or all of them), in the only order that produces stable output.
#
#   SHOWCASE_PASSWORD='...' ./scripts/capture.sh iam
#   SHOWCASE_PASSWORD='...' ./scripts/capture.sh            # every manifest
#   CAPTURE_MODE=native SHOWCASE_PASSWORD='...' ./scripts/capture.sh iam   # fast local iteration
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
#
# WHERE THE BROWSER RUNS
#
# By default the capture runs inside mcr.microsoft.com/playwright:v<pinned>-noble, as linux/amd64,
# on the compose network. That is not a convenience; it is what makes a screenshot taken on a
# laptop comparable to one taken in CI.
#
#   * macOS rasterises glyphs through CoreText, Linux through FreeType. A native Mac capture is
#     visually correct and differs from a Linux one by more than the compare gate's 0.2% floor
#     — every file would read as changed on the first CI run after it.
#   * A native ubuntu-latest runner is not the same as the Playwright image either: the runner
#     ships a much larger system font set, so fontconfig resolves fallbacks differently.
#   * Skia's rasteriser takes different SIMD paths on arm64 and amd64.
#
# So the rule is one image, one architecture, everywhere — and CI must invoke this script the same
# way. `CAPTURE_MODE=native` exists for iterating on a selector, where speed matters and pixels do
# not; never publish from it.
set -euo pipefail

cd "$(dirname "$0")/.."

: "${SHOWCASE_PASSWORD:?set SHOWCASE_PASSWORD}"
IDENTITY="${SHOWCASE_IDENTITY:-demo@fleetbase.io}"
MODE="${CAPTURE_MODE:-container}"

# The host always talks to the stack through the published port.
HOST_URL="http://localhost:${HTTP_PORT:-80}"

AREAS=("$@")
if [[ ${#AREAS[@]} -eq 0 ]]; then
    AREAS=()
    for m in capture/manifests/*.mjs; do
        AREAS+=("$(basename "$m" .mjs)")
    done
fi

case "$MODE" in
    container | native) ;;
    *)
        echo "::error::CAPTURE_MODE must be 'container' or 'native', got '${MODE}'"
        exit 1
        ;;
esac

echo "==> Authenticating as ${IDENTITY}"
TOKEN="$(curl -fsS -X POST "${HOST_URL}/int/v1/auth/login" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"identity\":\"${IDENTITY}\",\"password\":\"${SHOWCASE_PASSWORD}\"}" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))')"

if [[ -z "$TOKEN" ]]; then
    echo "::error::Could not authenticate as ${IDENTITY}. Has the organization been seeded?"
    exit 1
fi
echo "::add-mask::${TOKEN}"

echo "==> Freezing server-side timestamps"
# Redirected rather than `base64 -i <file>`: -i means "read this file" on macOS but
# "--ignore-garbage" in GNU coreutils, so the macOS spelling is a portability trap for a script
# that has to run on a developer's Mac and on an ubuntu runner.
CODE="$(base64 < scripts/freeze-timestamps.php | tr -d '\n')"
OUT="$(docker compose exec -T \
    -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Pte Ltd}" \
    application php artisan tinker --execute="eval(base64_decode('${CODE}'));" 2>&1 || true)"
printf '%s\n' "$OUT" | grep -E '^(FROZEN_|FRESHENED_|FREEZE_ERROR)' || true
if printf '%s\n' "$OUT" | grep -q 'FREEZE_ERROR'; then
    echo "::error::Could not freeze timestamps; captures will not be reproducible."
    exit 1
fi

SEEDED_FIXTURES="$(cat .seeded-fixtures.json 2>/dev/null || true)"

# The Google Maps pass runs only with a key, which up.sh carried into .env. Without one the
# Leaflet captures are the whole output, which is the default and the deterministic one.
GOOGLE_MAPS_API_KEY="${GOOGLE_MAPS_API_KEY:-$(grep '^GOOGLE_MAPS_API_KEY=' .env 2>/dev/null | cut -d= -f2- || true)}"

# Flip the live map provider for the showcase organization; see scripts/map-provider.php.
# Switch the owner between an ordinary user and a system admin; see scripts/user-type.php.
set_user_type() {
    local type="$1"
    local code out
    code="$(base64 < scripts/user-type.php | tr -d '\n')"
    out="$(docker compose exec -T \
        -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Pte Ltd}" \
        -e "SHOWCASE_USER_TYPE=${type}" \
        application php artisan tinker --execute="eval(base64_decode('${code}'));" 2>&1 || true)"
    printf '%s\n' "$out" | grep -E '^USER_TYPE' || true
    if ! printf '%s\n' "$out" | grep -q "^USER_TYPE=${type}$"; then
        echo "::error::Could not switch the showcase owner to ${type}."
        return 1
    fi
}

set_map_provider() {
    local provider="$1"
    local code out
    code="$(base64 < scripts/map-provider.php | tr -d '\n')"
    out="$(docker compose exec -T \
        -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Pte Ltd}" \
        -e "SHOWCASE_MAP_PROVIDER=${provider}" \
        application php artisan tinker --execute="eval(base64_decode('${code}'));" 2>&1 || true)"
    printf '%s\n' "$out" | grep -E '^MAP_PROVIDER' || true
    if ! printf '%s\n' "$out" | grep -q "^MAP_PROVIDER=${provider}$"; then
        echo "::error::Could not switch the map provider to ${provider}."
        return 1
    fi
}

if [[ "$MODE" == "container" ]]; then
    # Single-sourced from capture/package.json, so the image and the npm package cannot drift
    # apart. bin/capture.mjs also refuses to run when PLAYWRIGHT_IMAGE_TAG does not match.
    PW_VERSION="$(python3 -c 'import json; print(json.load(open("capture/package.json"))["dependencies"]["playwright"])')"
    IMAGE_TAG="v${PW_VERSION}-noble"
    IMAGE="mcr.microsoft.com/playwright:${IMAGE_TAG}"

    PROXY_ID="$(docker compose ps -q proxy)"
    if [[ -z "$PROXY_ID" ]]; then
        echo "::error::The proxy container is not running. Start the stack with scripts/up.sh."
        exit 1
    fi
    NETWORK="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' "$PROXY_ID")"

    echo "==> Capturing in ${IMAGE} (linux/amd64) on ${NETWORK}"
fi

capture_area() {
    local area="$1" provider="$2"
    local manifest="manifests/${area}.mjs"
    if [[ ! -f "capture/${manifest}" ]]; then
        echo "::error::no manifest at capture/${manifest}"
        exit 1
    fi

    echo
    echo "==> Capturing ${area} (${provider})"
    if [[ "$provider" == "leaflet" ]]; then
        # Clear the area's output first. Without this, a file from an entry that has since been
        # removed from the manifest survives in out/ and compare.mjs would happily stage it —
        # the pull request then updates a screenshot nothing captures any more. The Google pass
        # writes into the same directory afterwards, so only the first pass clears it.
        rm -rf "capture/out/${area}"
    fi

    if [[ "$MODE" == "native" ]]; then
        (
            cd capture
            BASE_URL="$HOST_URL" API_HOST="$HOST_URL" SHOWCASE_TOKEN="$TOKEN" SEEDED_FIXTURES="$SEEDED_FIXTURES" \
                npm run --silent capture -- --manifest "$manifest" --out "./out/${area}" --map-provider "$provider"
        )
        return 0
    fi

    # Inside the compose network the proxy is reachable as http://proxy on port 80, and that is
    # exactly the origin the published console needs: it derives its API host from
    # window.location and drops the port.
    #
    # node_modules lives in a named volume, not the bind mount. The host's node_modules holds
    # sharp built for darwin-arm64, which cannot load in a linux-amd64 container; the volume
    # keeps a Linux install that survives between runs and is reinstalled only when the lockfile
    # changes.
    docker run --rm --init --ipc=host --platform linux/amd64 \
        --network "$NETWORK" \
        -v "$PWD/capture:/work" \
        -v fleetbase-showcase-capture-node-modules:/work/node_modules \
        -w /work \
        -e CI=1 \
        -e "PLAYWRIGHT_IMAGE_TAG=${IMAGE_TAG}" \
        -e BASE_URL=http://proxy \
        -e API_HOST=http://proxy \
        -e "SHOWCASE_TOKEN=${TOKEN}" \
        -e "SEEDED_FIXTURES=${SEEDED_FIXTURES}" \
        -e "CAPTURE_DEBUG=${CAPTURE_DEBUG:-}" \
        -e "MANIFEST=${manifest}" \
        -e "AREA=${area}" \
        -e "MAP_PROVIDER=${provider}" \
        "$IMAGE" \
        bash -c '
            set -euo pipefail
            want="$(sha256sum package-lock.json | cut -d" " -f1)"
            have="$(cat node_modules/.lock-sha256 2>/dev/null || true)"
            if [[ "$want" != "$have" ]]; then
                echo "  installing capture dependencies for linux/amd64"
                npm ci --no-audit --no-fund >/dev/null
                echo "$want" > node_modules/.lock-sha256
            fi
            node bin/capture.mjs --manifest "$MANIFEST" --out "./out/$AREA" --map-provider "$MAP_PROVIDER"
        '
}

for area in "${AREAS[@]}"; do
    if [[ "$area" == "admin" ]]; then
        # Admin screens need a system admin. Restore on any exit: an owner left as admin would
        # put the admin console's extra navigation into every later area's screenshots.
        set_user_type admin
        trap 'set_user_type user >/dev/null 2>&1 || true' EXIT
        capture_area "$area" leaflet
        set_user_type user
        trap - EXIT
        continue
    fi
    capture_area "$area" leaflet
done

if [[ -n "$GOOGLE_MAPS_API_KEY" ]]; then
    echo
    echo "==> Google Maps pass (GOOGLE_MAPS_API_KEY is set)"
    set_map_provider google
    # Restore Leaflet whatever happens below: a stack left on Google would make the next
    # Leaflet run capture Google tiles under Leaflet file names.
    trap 'set_map_provider leaflet >/dev/null 2>&1 || true' EXIT
    for area in "${AREAS[@]}"; do
        capture_area "$area" google
    done
    set_map_provider leaflet
    trap - EXIT
else
    echo
    echo "    (no GOOGLE_MAPS_API_KEY — Google Maps pass skipped)"
fi

echo
echo "✔ Captured: ${AREAS[*]}"
