#!/usr/bin/env bash
# Builds the Storefront app for each edition, installs it on the booted simulator (iOS) or
# emulator (Android) and runs the app's own Maestro screenshot flows in dark and light.
#
#   ./scripts/mobile/capture.sh <ios|android> <app dir> <https://… stack URL> [editions…]
#
# Output: capture/out/storefront-app/<platform>/<shot>[-light].png, and Maestro's debug output
# (screenshots and view hierarchy at each failure) in debug/<edition>-<theme>/. Dark is the default theme
# and carries no suffix, matching the console naming rule (NAMING.md).
#
# CAPTURE_PHASE splits Android in two (default: all):
#   build  builds every edition's APK into capture/out/apks/ — run BEFORE booting the emulator:
#          Gradle's native compile next to a running emulator starves a hosted runner
#   run    installs those APKs and runs the flows
#
# Requires: a booted device, maestro on PATH, .mobile-fixtures.json (fixtures.sh), and for the
# sign-in screens STOREFRONT_BYPASS_VERIFICATION_CODE (the same value the stack was started with).
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"

PLATFORM="$1"
APP_DIR="$(cd "$2" && pwd)"
URL="$3"
shift 3
EDITIONS=("$@")
[[ ${#EDITIONS[@]} -eq 0 ]] && EDITIONS=(store network food-trucks network-food-trucks)

APP_ID="com.storefront.app"
# The iOS driver can take minutes to come up on a cold simulator after a build.
export MAESTRO_DRIVER_STARTUP_TIMEOUT="${MAESTRO_DRIVER_STARTUP_TIMEOUT:-600000}"
PHASE="${CAPTURE_PHASE:-all}"
APKS="${ROOT}/capture/out/apks"

OUT="${ROOT}/capture/out/storefront-app/${PLATFORM}"
FAILURES="${OUT}/failures.txt"
mkdir -p "$OUT"
: >"$FAILURES"

fixture() { python3 -c "import json,sys; f=json.load(open('.mobile-fixtures.json')); v=f.get('$1') or {}; print(v.get('$2','') if isinstance(v, dict) else v)"; }
PHONE="$(fixture customer_phone '')"

# The Google Services Gradle plugin refuses to build without a Firebase config, which the app's
# own CI writes from a secret. Push notifications play no part in screenshots, so a placeholder
# for the app id is enough when none is present.
android_firebase_placeholder() {
    local file="$APP_DIR/android/app/google-services.json"
    [[ -f "$file" ]] && return 0
    cat >"$file" <<JSON
{
  "project_info": { "project_number": "000000000000", "project_id": "storefront-screenshots", "storage_bucket": "storefront-screenshots.appspot.com" },
  "client": [
    {
      "client_info": { "mobilesdk_app_id": "1:000000000000:android:0000000000000000", "android_client_info": { "package_name": "${APP_ID}" } },
      "oauth_client": [],
      "api_key": [{ "current_key": "AIzaSyPlaceholderForScreenshotBuilds00000" }],
      "services": { "appinvite_service": { "other_platform_oauth_client": [] } }
    }
  ],
  "configuration_version": "1"
}
JSON
    echo "==> android: placeholder google-services.json (no Firebase config for this build)"
}

build_and_install() {
    case "$PLATFORM" in
        ios)
            # Release for the simulator: the JS is bundled (Debug loads it from Metro) and no
            # signing is needed. Incremental after the first edition: only the generated env changes.
            (cd "$APP_DIR/ios" && xcodebuild \
                -workspace StorefrontApp.xcworkspace -scheme StorefrontApp \
                -configuration Release -sdk iphonesimulator \
                -destination "id=${SIMULATOR_ID:?set SIMULATOR_ID}" \
                -derivedDataPath build CODE_SIGNING_ALLOWED=NO \
                build | tail -n 5)
            APP_PATH="$(find "$APP_DIR/ios/build/Build/Products/Release-iphonesimulator" -maxdepth 1 -name '*.app' | head -n1)"
            xcrun simctl uninstall "$SIMULATOR_ID" "$APP_ID" >/dev/null 2>&1 || true
            xcrun simctl install "$SIMULATOR_ID" "$APP_PATH"
            ;;
        android)
            # Release with the debug keystore when no upload key is configured: bundled JS,
            # installable on an emulator. One ABI (the emulator's) keeps the native build short.
            if [[ "$PHASE" != run ]]; then
                android_firebase_placeholder
                (cd "$APP_DIR/android" && ./gradlew --quiet assembleRelease -PreactNativeArchitectures=x86_64) || return 1
                mkdir -p "$APKS"
                cp "$APP_DIR/android/app/build/outputs/apk/release/app-release.apk" "$APKS/${EDITION}.apk" || return 1
            fi
            if [[ "$PHASE" != build ]]; then
                adb uninstall "$APP_ID" >/dev/null 2>&1 || true
                adb install -r "$APKS/${EDITION}.apk" || return 1
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

SKIPPED="${OUT}/skipped.txt"
: >"$SKIPPED"

for EDITION in "${EDITIONS[@]}"; do
    echo "==> ${PLATFORM}: ${EDITION}"
    # A Network's trucks need an API that returns member stores' trucks for a network key;
    # against an older release there is nothing to show, which is not a failure of the flow.
    if [[ "$EDITION" == network-food-trucks && "$(python3 -c "import json; print(json.load(open('.mobile-fixtures.json')).get('network_trucks', 1))")" == "0" ]]; then
        echo "network-trucks: this Fleetbase release returns no trucks for a network key" >>"$SKIPPED"
        echo "==> ${PLATFORM}: ${EDITION} skipped (no Network trucks in this release)"
        continue
    fi
    ./scripts/mobile/app-env.sh "$EDITION" "$APP_DIR" "$URL"
    if ! build_and_install; then
        echo "${EDITION}: build failed" >>"$FAILURES"
        continue
    fi
    [[ "$PHASE" == build ]] && continue

    # Flows declare no variable defaults (a flow's own env would override -e), so every one
    # is passed here. A Network names its screenshots -network and has its own Search tab.
    FLOW="$EDITION"
    QUALIFIER=""
    SEARCH_TAB="tab-store-search"
    case "$EDITION" in
        network) QUALIFIER="-network" ;;
        network-food-trucks) FLOW="food-trucks" QUALIFIER="-network" SEARCH_TAB="tab-network-search" ;;
    esac

    case "$EDITION" in
        food-trucks) KIND=truck_location ;;
        network-food-trucks) KIND=network_truck_location ;;
        *) KIND=store_location ;;
    esac
    LAT="$(fixture "$KIND" latitude)"
    LNG="$(fixture "$KIND" longitude)"

    for THEME in dark light; do
        appearance "$THEME"
        SUFFIX=""
        [[ "$THEME" == light ]] && SUFFIX="-light"
        SHOTS="$(mktemp -d)"
        DEBUG="${OUT}/debug/${EDITION}-${THEME}"
        mkdir -p "$DEBUG"
        touch "$SHOTS/.start"
        # Where takeScreenshot writes has moved between Maestro versions (the working directory,
        # the test output directory), so run in SHOTS with SHOTS as the output directory and
        # collect by name from anywhere it may have landed.
        # One retry: the first launch after a build sometimes loses the driver (iOS "driver not
        # ready in time") or a tap, and a second pass usually goes through.
        PASSED=false
        for ATTEMPT in 1 2; do
            if (cd "$SHOTS" && maestro test --test-output-dir "$DEBUG" "$APP_DIR/.maestro/screenshots/${FLOW}.yaml" \
                -e "APP_ID=${APP_ID}" \
                -e "SHOT_SUFFIX=${SUFFIX}" \
                -e "QUALIFIER=${QUALIFIER}" \
                -e "SEARCH_TAB=${SEARCH_TAB}" \
                -e "SEARCH_QUERY=${SEARCH_QUERY:-tea}" \
                -e "LATITUDE=${LAT:-1.2834}" \
                -e "LONGITUDE=${LNG:-103.8607}" \
                -e "CUSTOMER_PHONE=${PHONE}" \
                -e "VERIFICATION_CODE=${STOREFRONT_BYPASS_VERIFICATION_CODE:-}"); then
                PASSED=true
                break
            fi
            echo "==> ${EDITION} (${THEME}): attempt ${ATTEMPT} failed"
        done
        [[ "$PASSED" == true ]] || echo "${EDITION} (${THEME}): flow failed; partial screenshots kept" >>"$FAILURES"
        find "$SHOTS" "$DEBUG" "$APP_DIR/.maestro" "$HOME/.maestro/tests" -name 'storefront-app-*.png' -newer "$SHOTS/.start" -exec mv {} "$OUT/" \; 2>/dev/null || true
        rm -rf "$SHOTS"
        # Debug output (a screenshot and hierarchy per step) is only worth keeping for a failure.
        [[ "$PASSED" == true ]] && rm -rf "$DEBUG"
    done
done

if [[ "$PHASE" == build ]]; then
    echo "==> ${PLATFORM}: APKs in ${APKS#"$ROOT"/}"
    ls "$APKS" 2>/dev/null || true
    if [[ -s "$FAILURES" ]]; then
        cat "$FAILURES"
        # Nothing to install means nothing to capture; stop before booting an emulator.
        compgen -G "$APKS/*.apk" >/dev/null || exit 1
    fi
    exit 0
fi

COUNT="$(find "$OUT" -maxdepth 1 -name 'storefront-app-*.png' | wc -l | tr -d ' ')"
echo "==> ${PLATFORM}: ${COUNT} screenshots in ${OUT#"$ROOT"/}"
if [[ -s "$FAILURES" ]]; then
    echo "::warning::Some ${PLATFORM} flows failed:"
    cat "$FAILURES"
fi
