#!/usr/bin/env bash
# Every top-level path segment the API serves must be claimed by the Caddyfile's @api matcher.
#
# This is the guard against the nastiest failure mode in the whole pipeline. An API path the
# proxy does not claim falls through to the console, which answers 200 text/html for anything.
# The app then fails to parse JSON and renders an empty table — and an empty table screenshots
# perfectly. Nothing errors, nothing is red, and a screenshot of a broken screen ships to the
# marketing site.
#
# It is not hypothetical: /storefront and /ledger are simultaneously console engine mounts and
# API prefixes, so the naive "/int goes to the API, everything else to the console" rule is
# already wrong today. A future release adding a prefix would reintroduce the same bug, and
# this is what turns that into a failed run.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Checking the Caddyfile @api matcher against the API's own route table"

# Top-level segment of every route Laravel serves, minus parameter segments.
#
# A read loop rather than `mapfile`: macOS ships bash 3.2, where mapfile does not exist, and
# this script has to run identically on a developer's Mac and on an ubuntu-latest runner.
served=()
while IFS= read -r line; do
    served+=("$line")
done < <(
    docker compose exec -T application php artisan route:list --json 2>/dev/null \
        | jq -r '.[].uri' | cut -d/ -f1 | grep -v '^{' | grep -v '^$' | sort -u
)

if [[ ${#served[@]} -eq 0 ]]; then
    echo "::error::Could not read the route table from the application container"
    exit 1
fi

# Top-level segment of every path the @api matcher claims. Only the first segment of each
# path counts: /ledger/public/* claims ledger, not public — counting every segment once let
# Fleet-Ops' /public/* API go unclaimed while this check passed.
claimed="$(
    # Range ends at the `handle` that consumes the matcher, not at a particular path — an
    # earlier version ended at /uploads/ and silently stopped seeing everything added after it.
    sed -n '/@api path/,/handle @api/p' docker/caddy/Caddyfile \
        | grep -oE '(^|[[:space:]])/[^[:space:]\\]*' | tr -d ' \t' | cut -d/ -f2 | grep -v '^$' | sort -u
)"

fail=0
for seg in "${served[@]}"; do
    # The SPA legitimately owns these; they are not API routes even though Laravel knows them.
    case "$seg" in
        _ignition | _debugbar | telescope | horizon) continue ;;
    esac
    if ! grep -qx "$seg" <<< "$claimed"; then
        echo "::error::The API serves /${seg} but the Caddyfile @api matcher does not claim it."
        echo "         Requests to it will fall through to the console and return HTML."
        fail=1
    fi
done

if [[ $fail -eq 0 ]]; then
    echo "  ✔ every served prefix is claimed ($(wc -w <<< "$claimed" | tr -d ' ') claimed, ${#served[@]} served)"
fi
exit $fail
