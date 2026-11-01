# fleetbase/showcase

Marketing screenshot pipeline. On each Fleetbase release this boots the stack **from the
published Docker images**, seeds its own demo data, captures the console, and opens a pull
request on [fleetbase.io](https://github.com/fleetbase/fleetbase.io) with the screenshots that
actually changed.

It lives outside the open-source monorepo for two reasons. Demo fixture data and marketing
tooling do not belong in AGPL product repos — and a pipeline living in the OSS monorepo could
only ever screenshot the open-source engines, never `pallet`, `billing` or `flespi`.

```
fleetbase/fleetbase  v0.7.58 tag
  └─ publish-docker-images.yml → pushes fleetbase-{console,api}:v0.7.58
                               → repository_dispatch → here
                                            │
  boot published images behind ONE proxy on http://localhost
  → provision → seed → capture → two-gate compare → one PR
```

## Quick start

```bash
RELEASE_TAG=v0.7.59 ./scripts/up.sh
```

```bash
./scripts/provision.sh
```

```bash
SHOWCASE_PASSWORD='ShowcaseDemo1!' ./scripts/seed.sh
```

```bash
cd capture && npm ci && npx playwright install chromium && cd ..
```

```bash
SHOWCASE_PASSWORD='ShowcaseDemo1!' ./scripts/capture.sh iam
```

Always capture through `scripts/capture.sh`, not by calling the capture package directly. It
enforces an ordering that matters: authenticate → freeze server-side timestamps → capture with
the token already in hand. A login performed *inside* the capture writes `last_login`, which
the IAM users screen renders — so the file would differ on every run. Measured: with the login
inside, 6 of 8 files were byte-identical across two runs; with this ordering, 8 of 8 are.

## Running it from GitHub

The usual way to get screenshots is the **Console Screenshots** workflow — Actions → Run
workflow. Give it a release tag and the areas to capture; it uploads the results as an artifact.
Opening a pull request on fleetbase.io is opt-in via `open_pr`, so a plain run only produces
images to look at.

It needs one secret, `SHOWCASE_PASSWORD` (plus `FLEETBASE_IO_TOKEN` if you turn `open_pr` on).
A run takes well over half an hour, most of it in `deploy.sh` — `PermissionSeeder` alone is
about nine minutes before any browser starts.

Prefer the workflow over a local run for anything you intend to publish. It is amd64 natively,
whereas the console image is amd64-only and runs under slow emulation on Apple silicon.

## The four things that will surprise you

**1. Everything is on port 80, and that is not negotiable.** The published console image is
built `ENVIRONMENT=production`, so `API_HOST` is empty and `disableRuntimeConfig` is true — it
never fetches `/fleetbase.config.json`, even though the image ships one. So mounting a config
over it does nothing. `@fleetbase/ember-core`'s fetch service then falls back to
`` `${window.location.protocol}//${window.location.hostname}` `` — **dropping the port**. The
API therefore has to answer on the same origin on the default port, which is what
`docker/caddy/Caddyfile` is for. It is also how a real deployment is shaped, so the screenshots
are more faithful this way, not less.

**2. Some prefixes are both a console route and an API route.** `/storefront/int/v1/dashboard`
is the API; `/storefront/products` is the SPA. Same for `ledger`, `valhalla`, `vroom`,
`customer-portal` and `ai`. The proxy's `@api` matcher is ordered accordingly.

Get this wrong and nothing looks broken: an unclaimed API path falls through to the console,
which answers `200 text/html` for anything, the app fails to parse JSON, and the screen renders
as an empty table that screenshots perfectly. Two guards exist because of this —
`scripts/assert-routes.sh` diffs the matcher against `php artisan route:list` at provision
time, and the capture fails any entry whose `/int/` request comes back as HTML. The first draft
of that matcher was missing five real prefixes; the guard found them.

**3. The theme comes from the server, not the browser.** Playwright's `colorScheme` does
nothing on its own: `GET int/v1/settings/branding` returns `brand.default_theme` (`"dark"` on a
stock install) and the console applies it over `prefers-color-scheme`. Verified — emulating
light and dark both produced `body[data-theme="dark"]`. The one lever that wins is the
persisted user option at `<user uuid>:theme` in the `@fleetbase/storage:user-options` bucket,
which is why the capture fetches the uuid from `auth/session` before the browser starts.
Discovering it after boot is a race that silently leaves every capture on the brand theme.

**4. An empty ember-ui table still renders a row.** It has class
`next-table-empty-state-row`, so a plain `tbody tr` selector matches it and an `expectRows`
check of `min: 1` would pass on a table with no data. The manifest's `TABLE_ROW` excludes it.
That is the difference between "the seeder failed" and "we published a picture of an empty
state".

## Layout

| Path | What |
| --- | --- |
| `docker-compose.yml` | The stack. Every image pinned to `${RELEASE_TAG}`; nothing is built. |
| `docker/caddy/Caddyfile` | The single-origin proxy and its ordered path map. |
| `scripts/` | `up` → `provision` → `seed` → `capture`, plus `assert-routes` and `freeze-timestamps`. |
| `seeders/*/Concerns/BelongsTo*.php` | Per-area seed tags. Areas share tables (users, most notably), so each tags its own rows or one area's purge deletes another's. |
| `seeders/` | Bind-mounted into the running API container as `Database\Seeders\Showcase\`. |
| `capture/` | The Playwright capture package and its manifests. |

## Seeders

They are bind-mounted to `/fleetbase/api/database/seeders/Showcase`, which is already inside
the image's `Database\Seeders\` PSR-4 prefix — so no autoload surgery, and editing one is a
file save rather than a rebuild. `scripts/seed.sh` re-dumps the autoloader anyway, which is
what makes *adding* a class work without thinking about it.

`seeders/Concerns/SeedsShowcaseData.php` is a deliberate fork of fleetops'
`SeedsTestingData`. It has to be: that trait declares `SEED_NAME` as a **constant**, and PHP
rejects a class that redeclares a trait constant with a different value — so a showcase seeder
using it would tag its rows `fleetops-testing` and get purged by fleetops' own seeder. The fork
is small and dependency-light so the drift stays cheap.

No Faker: the published API image is installed `--no-dev`. Fixed values are what determinism
wants anyway.

### Status

| Area | Seeder | Manifest |
| --- | --- | --- |
| Organization | ✅ | — |
| IAM | ✅ | ✅ 4 entries, captured |
| Fleet-Ops | ✅ | ✅ 14 entries, captured |
| General | ⬜ | ⬜ |
| Developers | ⬜ | ⬜ |
| Storefront | ⬜ | ⬜ |
| Ledger | ⬜ | ⬜ |

## Storefront app (iOS and Android)

`.github/workflows/storefront-app-screenshots.yml` captures the Storefront mobile app on the iOS Simulator (iPhone 16 Pro Max, 1320×2868) and the Android Emulator (Pixel 7 Pro), against the same seeded release stack, and proposes the changed images under `public/images/screenshots/storefront-app/{ios,android}/` on fleetbase.io.

- **The flows live in the app**, in [`storefront-app/.maestro/screenshots`](https://github.com/fleetbase/storefront-app/tree/main/.maestro/screenshots), so a screen and the flow that walks through it change in the same pull request. This repo provides the stack, the build settings and the publishing.
- **Trigger:** storefront-app's release workflow sends `repository_dispatch` `storefront-app-release` with the tag; or run it by hand with `app_ref`, `release_tag`, `platforms`, `themes` (e.g. `light` for a light-only run) and `open_pr`. Release runs open the pull request when `OPEN_PR_ON_RELEASE` is `true`, as for the console.
- **How the devices reach the stack:** iOS only runs on macOS, macOS runners can't run the Docker stack, and iOS only allows HTTPS to unknown hosts. So the stack job opens a `cloudflared` quick tunnel (`scripts/mobile/tunnel.sh`) and starts the stack with it as `APP_URL`, so image URLs resolve too. The captures run in parallel jobs, one per platform and edition: Android on an emulator (Ubuntu, KVM), iOS on the simulator (macOS). They hand over through artifacts (`mobile-stack`, encrypted with `SHOWCASE_PASSWORD` and the run id, and `done-<platform>-<edition>`), because job outputs only arrive once a job has finished, and the gallery and publish jobs merge the editions' outputs (`scripts/mobile/merge-captures.sh`).
- **What the app is built with:** `scripts/mobile/fixtures.sh` reads the seeded store and network keys, the SDK key, the store and food-truck coordinates and the review customer from the database (`scripts/mobile-fixtures.php`), and `scripts/mobile/app-env.sh` writes the app's `.env` per edition: store, network, food trucks and a Network's food trucks (`HOME_SCREEN=foodTrucks` with the store or network key). For the trucks homes the customer is placed inside the truck's zone. Besides the store's truck, the Storefront seeder gives the network member store Rochor Noodle House a truck (van GBB-1010), so the Network edition has trucks too. Each edition is its own Release build, because the values are compiled in, which is why each edition gets a job of its own. iOS builds arm64 only, and CocoaPods resolves through its CDN instead of cloning the Specs repository the app's Podfile names.
- **Signing in:** the real flow, with Storefront's review-account bypass. Each run generates a code, starts the stack with `STOREFRONT_BYPASS_VERIFICATION_CODE` and `STOREFRONT_REVIEW_ACCOUNTS=+6591000201` (the seeded customer Ava Chen, who has order history and an order in progress at the store and in the Network, so both editions capture account, orders and checkout), and passes both to the flow.
- **Determinism:** fixed devices and locale, status bar overrides on both platforms (9:41, full battery), animations off on Android, seeded timestamps. Map tiles still vary slightly; the pixel gate absorbs that. There is no DOM on a phone, so the compare is pixels-only.
- **Review:** every run uploads `storefront-app-gallery`: unzip it and open `index.html` for every expected screen side by side (iOS and Android, dark and light), with gaps marked and failed flows listed (`capture/bin/gallery-mobile.mjs`, screen list in `capture/src/storefront-app-screens.mjs`). A run goes red when any flow stopped early.
- **Output:** `capture/out/storefront-app/<platform>/*.png`, encoded to lossless WebP by `capture/bin/encode-mobile.mjs` (the existing app screenshots are lossless). Dark is unsuffixed, light is `-light`, names follow [NAMING.md](NAMING.md).

Optional: `STOREFRONT_APP_GOOGLE_MAPS_API_KEY` (Android map tiles: a key with the Maps SDK for Android enabled for package `com.storefront.app` and the React Native debug signing SHA-1 `5E:8F:16:06:2E:A3:CD:2C:4A:0D:54:78:76:BA:A6:F3:8C:AB:F6:25`; falls back to `GOOGLE_MAPS_API_KEY`), and the org's `STRIPE_TEST_KEY` / `STRIPE_TEST_SECRET` (secrets or variables): the seeded Stripe gateways get the test keys and the app is built with the publishable one, so checkout creates a real test payment.

Locally, with a booted simulator and `maestro` installed:

```bash
URL="$(./scripts/mobile/tunnel.sh)"
APP_URL="$URL" STOREFRONT_BYPASS_VERIFICATION_CODE=123456 STOREFRONT_REVIEW_ACCOUNTS=+6591000201 ./scripts/up.sh
./scripts/provision.sh && SHOWCASE_PASSWORD='…' ./scripts/seed.sh && ./scripts/mobile/fixtures.sh
SIMULATOR_ID=<udid> STOREFRONT_BYPASS_VERIFICATION_CODE=123456 ./scripts/mobile/capture.sh ios ../storefront-app "$URL" store
```

## Google Maps variants

The console can render its *live operations map* with Google Maps instead of Leaflet
(Fleet-Ops → Settings → Map). With a `GOOGLE_MAPS_API_KEY` in the environment — the
`GOOGLE_MAPS_API_KEY` repository secret in CI, or `GOOGLE_MAPS_API_KEY=… ./scripts/up.sh`
locally — `scripts/capture.sh` runs a second pass after the Leaflet one: it switches the
showcase organization's map provider to Google, captures every manifest entry that declares
`map.google` with a `-google` suffix (`fleet-ops-live-orders-panel-google.webp`,
`…-google-light.webp`), and switches back.

What to know before enabling it:

- Only the live map (orders index, order details, the drawer's position listing) switches
  providers. Geofence and service-area editors, place pickers and the tracking page are
  Leaflet-only in the engine, so there is nothing to capture for them.
- Restrict the key by HTTP referrer to `http://proxy/*` (the capture container) and
  `http://localhost/*` (a laptop). The key is never written into the image set; the console
  fetches it from the API at runtime.
- Google's terms forbid caching tiles, so this pass hits the network live and its output is
  not byte-stable between runs. `compare.mjs` therefore reports Google captures but does not
  stage them into the fleetbase.io pull request unless run with `--include-google`.
- Keep Google's logo and attribution visible in anything published.

## Local caveats

Output from a native macOS run is visually correct but **not** byte-comparable to CI — CoreText
and FreeType rasterise glyphs differently, so every file would read as changed. For a
comparable run, execute the capture inside `mcr.microsoft.com/playwright:v1.62.1-noble`.

The console image is published **amd64-only** (the API is multi-arch), so it runs under
emulation on Apple silicon — slowly enough that the browser can exceed Playwright's default
30-second navigation timeout. `CAPTURE_NAV_TIMEOUT_MS` raises it; the default here is already
120s for that reason. If a local capture is painful, run the workflow instead.

The capture package needs **Node 22** (`engines` says so). Node 18 cannot expand the glob in the
test script and cannot load the bundled sharp binary, and the failure looks like missing test
files rather than a wrong runtime.

If your own Fleetbase dev stack already owns port 38000, set `WSS_PORT` to something else. You
lose the realtime screens — the console has `38000` compiled in — but everything else captures.
