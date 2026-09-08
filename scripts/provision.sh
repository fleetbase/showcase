#!/usr/bin/env bash
# Provision a freshly started stack: migrate, seed the platform, build permissions.
#
# The monorepo does this with scripts/docker-install.sh, which needs the monorepo checked out.
# We do not have one — but we do not need one, because the work itself lives inside the image
# as /fleetbase/api/deploy.sh. Running that is both simpler and guaranteed to stay in step
# with the release under test.
#
# deploy.sh runs: mysql:createdb, migrate, sandbox:migrate, fleetbase:seed,
# fleetbase:create-permissions, queue:restart, schedule-monitor:sync, cache/route clear,
# config:cache, route:cache, registry:init.
#
# Note the config:cache at the end. After it, Laravel stops reading the environment, so
# everything the API needs must already be in the container environment — which is why
# docker-compose.yml sets it all there and there is deliberately no api/.env.
set -euo pipefail

cd "$(dirname "$0")/.."

compose() { docker compose "$@"; }

echo "==> Running deploy.sh inside the application container"
compose exec -T application bash -c "./deploy.sh"

echo
echo "==> Asserting migrations actually ran"
compose exec -T application php artisan migrate:status > /tmp/showcase-migrate.txt
grep -Eiq "ran|yes" /tmp/showcase-migrate.txt || {
    echo "::error::No migrations report as run"; tail -20 /tmp/showcase-migrate.txt; exit 1
}
if grep -Eiq "pending" /tmp/showcase-migrate.txt; then
    echo "::error::Pending migrations remain after deploy.sh"
    grep -i pending /tmp/showcase-migrate.txt | head -10
    exit 1
fi

echo
echo "==> Waiting for the proxy to serve both roles on one origin"
# This is the assumption the whole repo rests on, so assert it rather than assume it: the API
# and the SPA must both answer on http://localhost, because the published console derives its
# API host from window.location and drops the port.
wait_for() {
    local name="$1" url="$2" expect="$3" tries=40
    for _ in $(seq 1 $tries); do
        body="$(curl -fsS --max-time 5 "$url" 2>/dev/null || true)"
        if echo "$body" | grep -q "$expect"; then echo "  ✔ ${name}"; return 0; fi
        sleep 3
    done
    echo "::error::${name} did not respond with '${expect}' at ${url} after $((tries * 3))s"
    return 1
}
wait_for "API  /health"        "http://localhost/health"          '"status"'
wait_for "SPA  /"              "http://localhost/"                "<!DOCTYPE html>"
wait_for "SPA  extensions.json" "http://localhost/extensions.json" "route"

echo
./scripts/assert-routes.sh

echo
echo "✔ Provisioned. Next: ./scripts/seed.sh"
