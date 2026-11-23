#!/usr/bin/env bash
# Reads what the Passenger app needs from the seeded stack (scripts/passenger-fixtures.php) and
# writes it to .passenger-fixtures.json: the API key, the order config, the rider's phone, the
# pickup and the online drivers. Run after `seed.sh passenger`.
set -euo pipefail

cd "$(dirname "$0")/../.."

CODE="$(base64 <scripts/passenger-fixtures.php | tr -d '\n')"
OUT="$(docker compose exec -T \
    -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Pte Ltd}" \
    application php artisan tinker --execute="eval(base64_decode('${CODE}'));" 2>&1 || true)"

if printf '%s\n' "$OUT" | grep -q 'PASSENGER_FIXTURES_ERROR'; then
    echo "::error::$(printf '%s\n' "$OUT" | grep -o 'PASSENGER_FIXTURES_ERROR=.*' | cut -d= -f2-)"
    exit 1
fi

JSON="$(printf '%s\n' "$OUT" | grep -oE 'PASSENGER_FIXTURES=\{.*\}' | cut -d= -f2- | tail -n1)"
if [[ -z "$JSON" ]]; then
    echo "::error::scripts/passenger-fixtures.php printed no PASSENGER_FIXTURES line"
    printf '%s\n' "$OUT" | tail -n 20
    exit 1
fi

printf '%s\n' "$JSON" >.passenger-fixtures.json

python3 - <<'PY'
import json
f = json.load(open('.passenger-fixtures.json'))
for key in ('api_key', 'order_config', 'rider_phone', 'pickup'):
    if not f.get(key):
        raise SystemExit(f'::error::.passenger-fixtures.json is missing {key}')
if not f.get('drivers'):
    raise SystemExit('::error::.passenger-fixtures.json has no online drivers')
# A credential of a throwaway stack, but it still doesn't belong in a public log.
print('::add-mask::' + f['api_key'])
print(f"==> Passenger fixtures: order config {f['order_config']}, rider, pickup at {f['pickup']}, "
      f"{len(f['drivers'])} online drivers")
PY
