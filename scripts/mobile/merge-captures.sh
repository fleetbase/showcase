#!/usr/bin/env bash
# Merges the per-edition capture artifacts (storefront-app-<platform>-<edition>/, one per
# workflow job) into one storefront-app-<platform>/ per platform, the layout the gallery and
# the publish step read: every screenshot, the debug output, and failures.txt and skipped.txt
# concatenated.
#
#   ./scripts/mobile/merge-captures.sh <dir with the downloaded artifacts>
set -euo pipefail

RAW="$1"

for platform in ios android; do
    parts=()
    for dir in "$RAW"/storefront-app-"$platform"-*/; do
        if [[ -d "$dir" ]]; then parts+=("${dir%/}"); fi
    done
    if [[ ${#parts[@]} -eq 0 ]]; then continue; fi

    out="$RAW/storefront-app-$platform"
    mkdir -p "$out"
    : >"$out/failures.txt"
    : >"$out/skipped.txt"
    for dir in "${parts[@]}"; do
        find "$dir" -maxdepth 1 -name '*.png' -exec cp {} "$out/" \;
        if [[ -d "$dir/debug" ]]; then
            mkdir -p "$out/debug"
            cp -R "$dir/debug/." "$out/debug/"
        fi
        for list in failures.txt skipped.txt; do
            if [[ -f "$dir/$list" ]]; then cat "$dir/$list" >>"$out/$list"; fi
        done
        rm -rf "$dir"
    done
    echo "==> $platform: $(find "$out" -maxdepth 1 -name '*.png' | wc -l | tr -d ' ') screenshots from ${#parts[@]} editions"
done
