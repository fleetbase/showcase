#!/usr/bin/env bash
# Builds the Passenger app, installs it on the booted simulator (iOS) or emulator (Android) and
# runs the app's own Maestro screenshot flows in dark and light (CAPTURE_THEMES, e.g. "light"
# for a light-only run), with the capture's driver actor (passenger-driver.py) taking the trip
# between them.
#
#   ./scripts/mobile/passenger-capture.sh <ios|android> <app dir> <https://… stack URL>
#
# One trip per theme, in seven flows (passenger-app/.maestro/screenshots):
#
#   01-sign-in      sign-in, code, home
#   02-account      account, trips, a past trip, its receipt, saved places, help
#   03-book         drop-off, car classes and fares, request → "Finding your driver"
#                       actor: accept
#   04-en-route     the driver on the way          actor: arrive
#   05-arrived      the driver at the pickup       actor: start
#   06-in-progress  on the way to the drop-off     actor: complete
#   07-complete     final fare and rating          actor: clear (deletes the trip)
#
# Deleting the trip at the end keeps the rider's history the seeded one, so the light run's
# trips screen matches the dark run's.
#
# Output: capture/out/passenger-app/<platform>/<shot>[-light].png, and Maestro's debug output
# in debug/<theme>/<flow>/ for a flow that failed. Dark is unsuffixed (NAMING.md).
#
# CAPTURE_PHASE splits the work in two, as for Storefront (default: all):
#   build  builds the app into capture/out/builds/passenger.{apk,app}
#   run    installs that build and runs the flows
#
# Requires: a booted device, maestro on PATH, .passenger-fixtures.json (passenger-fixtures.sh),
# and PASSENGER_VERIFICATION_CODE, the FLEETOPS_CUSTOMER_VERIFICATION_BYPASS_CODE the stack was
# started with.
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"

PLATFORM="$1"
APP_DIR="$(cd "$2" && pwd)"
URL="${3%/}"

APP_ID="io.fleetbase.passenger"
export MAESTRO_DRIVER_STARTUP_TIMEOUT="${MAESTRO_DRIVER_STARTUP_TIMEOUT:-600000}"
PHASE="${CAPTURE_PHASE:-all}"
BUILDS="${ROOT}/capture/out/builds"
read -r -a THEMES <<<"${CAPTURE_THEMES:-dark light}"
for THEME in "${THEMES[@]}"; do
    [[ "$THEME" == dark || "$THEME" == light ]] || { echo "::error::CAPTURE_THEMES: unknown theme ${THEME}" >&2; exit 1; }
done

OUT="${ROOT}/capture/out/passenger-app/${PLATFORM}"
FAILURES="${OUT}/failures.txt"
mkdir -p "$OUT"
: >"$FAILURES"
: >"${OUT}/skipped.txt"

fixture() { python3 -c "import json; f=json.load(open('.passenger-fixtures.json')); v=f.get('$1'); print((v or {}).get('$2', '') if '$2' else v)"; }
PHONE="$(fixture rider_phone '')"
LAT="$(fixture pickup latitude)"
LNG="$(fixture pickup longitude)"

build_and_install() {
    case "$PLATFORM" in
        ios)
            # Release for the simulator: the JS is bundled and no signing is needed. arm64 only,
            # the runners' and Apple silicon's simulators.
            if [[ "$PHASE" != run ]]; then
                local log="${BUILDS}/passenger-xcodebuild.log"
                mkdir -p "$BUILDS"
                if ! (cd "$APP_DIR/ios" && xcodebuild \
                    -workspace PassengerApp.xcworkspace -scheme PassengerApp \
                    -configuration Release -sdk iphonesimulator \
                    -destination 'generic/platform=iOS Simulator' \
                    -derivedDataPath build CODE_SIGNING_ALLOWED=NO ARCHS=arm64 ONLY_ACTIVE_ARCH=YES \
                    -showBuildTimingSummary build >"$log" 2>&1); then
                    grep -E 'error:|\*\* BUILD FAILED' "$log" | head -n 20
                    tail -n 20 "$log"
                    return 1
                fi
                sed -n '/Build Timing Summary/,/BUILD SUCCEEDED/p' "$log" | head -n 25
                local app
                app="$(find "$APP_DIR/ios/build/Build/Products/Release-iphonesimulator" -maxdepth 1 -name '*.app' | head -n1)"
                rm -rf "$BUILDS/passenger.app"
                cp -R "$app" "$BUILDS/passenger.app" || return 1
            fi
            if [[ "$PHASE" != build ]]; then
                xcrun simctl uninstall "${SIMULATOR_ID:?set SIMULATOR_ID}" "$APP_ID" >/dev/null 2>&1 || true
                xcrun simctl install "$SIMULATOR_ID" "$BUILDS/passenger.app" || return 1
            fi
            ;;
        android)
            # Release with the debug keystore (no upload key is set): bundled JS, installable on
            # an emulator. One ABI, the emulator's.
            if [[ "$PHASE" != run ]]; then
                (cd "$APP_DIR/android" && ./gradlew --quiet assembleRelease -PreactNativeArchitectures=x86_64) || return 1
                mkdir -p "$BUILDS"
                cp "$APP_DIR/android/app/build/outputs/apk/release/app-release.apk" "$BUILDS/passenger.apk" || return 1
            fi
            if [[ "$PHASE" != build ]]; then
                adb uninstall "$APP_ID" >/dev/null 2>&1 || true
                adb install -r "$BUILDS/passenger.apk" || return 1
            fi
            ;;
    esac
}

appearance() {
    case "$PLATFORM" in
        ios) xcrun simctl ui "$SIMULATOR_ID" appearance "$1" ;;
        android) adb shell cmd uimode night "$([[ $1 == dark ]] && echo yes || echo no)" >/dev/null ;;
    esac
}

driver() { python3 scripts/mobile/passenger-driver.py "$1" --url "$URL"; }

./scripts/mobile/passenger-app-env.sh "$APP_DIR" "$URL"
if ! build_and_install; then
    echo "passenger: build failed" >>"$FAILURES"
    cat "$FAILURES"
    exit 1
fi
if [[ "$PHASE" == build ]]; then
    echo "==> ${PLATFORM}: build in ${BUILDS#"$ROOT"/}"
    exit 0
fi

# flow : the driver actor's step after it ("-" for none)
STEPS=(01-sign-in:- 02-account:- 03-book:accept 04-en-route:arrive 05-arrived:start 06-in-progress:complete 07-complete:clear)

run_flow() {
    local flow="$1" theme="$2" suffix="$3" shots="$4"
    local debug="${OUT}/debug/${theme}/${flow}"
    mkdir -p "$debug"
    # Where takeScreenshot writes has moved between Maestro versions, so run in SHOTS with SHOTS
    # as the output directory and collect by name from anywhere it may have landed.
    if (cd "$shots" && maestro test --test-output-dir "$debug" "$APP_DIR/.maestro/screenshots/${flow}.yaml" \
        -e "APP_ID=${APP_ID}" \
        -e "SHOT_SUFFIX=${suffix}" \
        -e "LATITUDE=${LAT}" \
        -e "LONGITUDE=${LNG}" \
        -e "RIDER_PHONE=${PHONE}" \
        -e "VERIFICATION_CODE=${PASSENGER_VERIFICATION_CODE:-}"); then
        # takeScreenshot may write into the output directory: keep the shots, drop the rest.
        find "$debug" -name 'passenger-app-*.png' -exec mv {} "$shots/" \; 2>/dev/null || true
        rm -rf "$debug"
        return 0
    fi
    find "$debug" -name 'passenger-app-*.png' -exec mv {} "$shots/" \; 2>/dev/null || true
    return 1
}

for THEME in "${THEMES[@]}"; do
    appearance "$THEME"
    SUFFIX=""
    [[ "$THEME" == light ]] && SUFFIX="-light"
    SHOTS="$(mktemp -d)"
    touch "$SHOTS/.start"
    rm -f .passenger-ride.json

    # One retry for the whole theme: the first launch after an install sometimes loses the
    # driver (iOS "driver not ready in time") or a tap. A trip left half-way is deleted first.
    for ATTEMPT in 1 2; do
        FAILED=""
        for STEP in "${STEPS[@]}"; do
            FLOW="${STEP%%:*}"
            ACTION="${STEP##*:}"
            echo "==> ${PLATFORM} (${THEME}): ${FLOW}"
            if ! run_flow "$FLOW" "$THEME" "$SUFFIX" "$SHOTS"; then
                FAILED="$FLOW"
                break
            fi
            if [[ "$ACTION" != - ]] && ! driver "$ACTION"; then
                FAILED="${FLOW} (driver ${ACTION})"
                break
            fi
            # While the trip is on, the driver keeps sending the car's position; it stops by
            # itself once the trip is cleared.
            if [[ "$ACTION" == accept ]]; then
                python3 scripts/mobile/passenger-driver.py heartbeat --url "$URL" &
            fi
        done
        [[ -z "$FAILED" ]] && break
        echo "==> ${THEME}: attempt ${ATTEMPT} stopped at ${FAILED}"
        [[ -f .passenger-ride.json ]] && driver clear || true
    done
    wait
    [[ -n "$FAILED" ]] && echo "${THEME}: stopped at ${FAILED}; partial screenshots kept" >>"$FAILURES"

    find "$SHOTS" "${OUT}/debug" "$APP_DIR/.maestro" "$HOME/.maestro/tests" -name 'passenger-app-*.png' -newer "$SHOTS/.start" -exec mv {} "$OUT/" \; 2>/dev/null || true
    rm -rf "$SHOTS"
done

COUNT="$(find "$OUT" -maxdepth 1 -name 'passenger-app-*.png' | wc -l | tr -d ' ')"
echo "==> ${PLATFORM}: ${COUNT} screenshots in ${OUT#"$ROOT"/}"
if [[ -s "$FAILURES" ]]; then
    echo "::warning::Some ${PLATFORM} flows failed:"
    cat "$FAILURES"
fi
