#!/usr/bin/env bash
# Writes the Passenger app's .env, pointed at the stack's public URL.
#
#   ./scripts/mobile/passenger-app-env.sh <app dir> <https://… stack URL>
#
# The values are compiled into the build (scripts/link-brand.js reads them), so a change needs a
# new build.
set -euo pipefail

cd "$(dirname "$0")/../.."

APP_DIR="$1"
URL="${2%/}"

python3 - "$APP_DIR" "$URL" <<'PY'
import json, os, sys

app_dir, url = sys.argv[1:3]
f = json.load(open('.passenger-fixtures.json'))

env = {
    'FLEETBASE_HOST': url,
    'FLEETBASE_API_KEY': f['api_key'],
    'FLEETBASE_ORDER_CONFIG': f['order_config'],
    'PASSENGER_SAMPLE_DATA': 'false',
    # The Android build signs a release with the debug key when no upload key is set, and reads
    # the application ID from here (android/app/build.gradle).
    'APP_IDENTIFIER': 'io.fleetbase.passenger',
}
# The Android map needs a key, or it draws an empty grid. iOS uses Apple Maps and needs none.
if os.environ.get('GOOGLE_MAPS_API_KEY'):
    env['GOOGLE_MAPS_API_KEY'] = os.environ['GOOGLE_MAPS_API_KEY']

with open(f'{app_dir}/.env', 'w') as out:
    out.write(''.join(f'{k}={v}\n' for k, v in env.items()))
print(f'==> {app_dir}/.env written for {url}')
PY
