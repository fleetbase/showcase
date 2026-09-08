#!/usr/bin/env bash
# Seed the showcase fixtures. With no argument, seeds everything; with one, seeds that area.
#
#   ./scripts/seed.sh                # all areas
#   ./scripts/seed.sh fleet-ops      # just Fleet-Ops — purges and reseeds only its rows
#
# The dump-autoload is unconditional and cheap (~2s). Editing an existing seeder does not need
# it — PHP reads the file from disk through the bind mount on the next artisan call — but
# ADDING a class does, and making it a manual step is how you lose ten minutes to a
# "class does not exist" that looks like a namespace bug.
set -euo pipefail

cd "$(dirname "$0")/.."

AREA="${1:-all}"

# A case statement rather than an associative array: macOS ships bash 3.2, which has no
# `declare -A`, and this script has to behave identically on a developer's Mac and on an
# ubuntu-latest runner.
AREAS="all organization general iam fleet-ops developers storefront ledger"
case "$AREA" in
    all)          CLASS='Database\Seeders\Showcase\ShowcaseSeeder' ;;
    organization) CLASS='Database\Seeders\Showcase\Organization\OrganizationSeeder' ;;
    general)      CLASS='Database\Seeders\Showcase\General\GeneralSeeder' ;;
    iam)          CLASS='Database\Seeders\Showcase\Iam\IamSeeder' ;;
    fleet-ops)    CLASS='Database\Seeders\Showcase\FleetOps\FleetOpsSeeder' ;;
    developers)   CLASS='Database\Seeders\Showcase\Developers\DevelopersSeeder' ;;
    storefront)   CLASS='Database\Seeders\Showcase\Storefront\StorefrontSeeder' ;;
    ledger)       CLASS='Database\Seeders\Showcase\Ledger\LedgerSeeder' ;;
    *)
        echo "::error::unknown area '${AREA}'. One of: ${AREAS}"
        exit 1
        ;;
esac

: "${SHOWCASE_PASSWORD:?set SHOWCASE_PASSWORD (the demo account password)}"

echo "==> Refreshing the autoloader"
docker compose exec -T application su www-data -s /bin/sh -c \
    "cd /fleetbase/api && composer dump-autoload --optimize --no-scripts" 2>&1 | tail -2

echo "==> Seeding ${AREA} (${CLASS})"
# --force because the container runs APP_ENV=production, where db:seed prompts otherwise.
OUT="$(docker compose exec -T \
    -e "SHOWCASE_IDENTITY=${SHOWCASE_IDENTITY:-demo@fleetbase.io}" \
    -e "SHOWCASE_PASSWORD=${SHOWCASE_PASSWORD}" \
    -e "SHOWCASE_ORG_NAME=${SHOWCASE_ORG_NAME:-Fleetbase Demo}" \
    application php artisan db:seed --force --class="${CLASS}" 2>&1 || true)"

printf '%s\n' "$OUT" | tail -30

if printf '%s\n' "$OUT" | grep -qiE 'does not exist|Target class .* does not exist'; then
    echo "::error::Seeder class ${CLASS} was not found. If you just added the file, the bind mount"
    echo "         may not include it — check docker-compose.yml's ./seeders mount."
    exit 1
fi
if printf '%s\n' "$OUT" | grep -q 'SHOWCASE_ERROR'; then
    echo "::error::A seeder threw. Everything after the failure was NOT created."
    exit 1
fi

# Echoed by the area seeders so the capture step can cross-check the records it resolved
# against the ones the seeder says it made. Two independent paths agreeing is what separates
# "resolved the right record" from "resolved a record".
FIXTURES="$(printf '%s\n' "$OUT" | grep -oE 'SEEDED_FIXTURES=\{.*\}' | tail -1 | cut -d= -f2- || true)"
if [[ -n "$FIXTURES" ]]; then
    echo "$FIXTURES" > .seeded-fixtures.json
    echo "  ✔ wrote .seeded-fixtures.json"
fi

echo
echo "✔ Seeded ${AREA}."
