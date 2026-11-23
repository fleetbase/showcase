#!/usr/bin/env bash
# Closes Android's "<app> isn't responding" dialog for any app but the one being captured, for
# as long as the capture runs.
#
#   ./scripts/mobile/android-anr-watcher.sh <app id> &
#
# On a busy hosted runner the emulator's own apps (the Pixel Launcher above all) stop answering
# for a while, and Android puts its dialog over whatever is on screen. Maestro then can't see
# the app under it, and every flow fails at its first check: all eight Network flows on
# 2026-11-23. `hide_error_dialogs` does not stop this dialog, so the watcher force-stops the
# hung app instead, which closes the dialog; Android starts the launcher again by itself.
#
# The app being captured is left alone: if it hangs, its flow should fail and say so.
APP_ID="${1:?app id}"

while true; do
    hung="$(adb shell dumpsys window windows 2>/dev/null | tr -d '\r' \
        | grep -oE 'Application Not Responding: [A-Za-z0-9._:]+' | sed 's/.*: //' \
        | grep -vxE "${APP_ID//./\\.}(:.*)?" | head -n1)"
    package="${hung%%:*}"
    if [[ -n "$package" ]]; then
        echo "==> android: ${package} stopped responding; closing it"
        adb shell am force-stop "$package" >/dev/null 2>&1 || true
    fi
    sleep 3
done
