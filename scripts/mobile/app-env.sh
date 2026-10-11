#!/usr/bin/env bash
# Writes the Storefront app's .env for one edition, pointed at the stack's public URL.
#
#   ./scripts/mobile/app-env.sh <store|network|food-trucks> <app dir> <https://… stack URL>
#
# The values are compiled into the native build (react-native-config), so every edition is
# its own build.
set -euo pipefail

cd "$(dirname "$0")/../.."

EDITION="$1"
APP_DIR="$2"
URL="${3%/}"

python3 - "$EDITION" "$APP_DIR" "$URL" <<'PY'
import json, sys, urllib.parse

edition, app_dir, url = sys.argv[1:4]
f = json.load(open('.mobile-fixtures.json'))
host = urllib.parse.urlparse(url)
secure = host.scheme == 'https'

env = {
    'APP_NAME': 'Storefront',
    'APP_IDENTIFIER': 'com.storefront.app',
    'FLEETBASE_HOST': url,
    'FLEETBASE_KEY': f['fleetbase_key'],
    'STOREFRONT_KEY': f['network_key'] if edition == 'network' else f['store_key'],
    # Realtime goes through the same origin: the proxy routes /socketcluster/ to the socket.
    'SOCKETCLUSTER_HOST': host.hostname,
    'SOCKETCLUSTER_PORT': str(host.port or (443 if secure else 80)),
    'SOCKETCLUSTER_SECURE': 'true' if secure else 'false',
    'SOCKETCLUSTER_PATH': '/socketcluster/',
    'DEFAULT_LOCALE': 'en',
    'AVAILABLE_LOCALES': 'en',
    'PAYMENT_GATEWAY': 'stripe',
}
if edition == 'food-trucks':
    env['HOME_SCREEN'] = 'foodTrucks'
location = f.get('truck_location' if edition == 'food-trucks' else 'store_location')
if location:
    env['DEFAULT_COORDINATES'] = f"{location['latitude']},{location['longitude']}"

import os
# Optional: maps on Android, and the Stripe test key the checkout screen shows the sheet with.
for key in ('GOOGLE_MAPS_API_KEY', 'STRIPE_KEY'):
    if os.environ.get(key):
        env[key] = os.environ[key]

with open(f'{app_dir}/.env', 'w') as out:
    out.write(''.join(f'{k}={v}\n' for k, v in env.items()))
print(f'==> {app_dir}/.env written for the {edition} edition')
PY
