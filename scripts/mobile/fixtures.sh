#!/usr/bin/env bash
# Reads what the Storefront app needs from the seeded stack (scripts/mobile-fixtures.php) and
# writes it to .mobile-fixtures.json: store and network keys, the SDK key, the store and food
# truck coordinates and the review customer's phone. Run after seed.sh.
set -euo pipefail

cd "$(dirname "$0")/../.."

CODE="$(base64 <scripts/mobile-fixtures.php | tr -d '\n')"
OUT="$(docker compose exec -T \
    -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Pte Ltd}" \
    application php artisan tinker --execute="eval(base64_decode('${CODE}'));" 2>&1 || true)"

if printf '%s\n' "$OUT" | grep -q 'MOBILE_FIXTURES_ERROR'; then
    echo "::error::$(printf '%s\n' "$OUT" | grep -o 'MOBILE_FIXTURES_ERROR=.*' | cut -d= -f2-)"
    exit 1
fi

JSON="$(printf '%s\n' "$OUT" | grep -oE 'MOBILE_FIXTURES=\{.*\}' | cut -d= -f2- | tail -n1)"
if [[ -z "$JSON" ]]; then
    echo "::error::scripts/mobile-fixtures.php printed no MOBILE_FIXTURES line"
    printf '%s\n' "$OUT" | tail -n 20
    exit 1
fi

printf '%s\n' "$JSON" >.mobile-fixtures.json
python3 - <<'PY'
import json
f = json.load(open('.mobile-fixtures.json'))
for key in ('store_key', 'network_key', 'fleetbase_key', 'customer_phone'):
    if not f.get(key):
        raise SystemExit(f'::error::.mobile-fixtures.json is missing {key}')
# Keys are credentials of a throwaway stack, but they still don't belong in a public log.
print('::add-mask::' + f['fleetbase_key'])
print('==> Mobile fixtures: store and network keys, SDK key, customer, '
      f"store at {f.get('store_location')}, truck at {f.get('truck_location')}")
PY
