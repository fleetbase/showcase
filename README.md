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
RELEASE_TAG=v0.7.57 ./scripts/up.sh
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

## Local caveats

Output from a native macOS run is visually correct but **not** byte-comparable to CI — CoreText
and FreeType rasterise glyphs differently, so every file would read as changed. For a
comparable run, execute the capture inside `mcr.microsoft.com/playwright:v1.62.1-noble`.

The console image is published **amd64-only** (the API is multi-arch), so it runs under
emulation on Apple silicon.

If your own Fleetbase dev stack already owns port 38000, set `WSS_PORT` to something else. You
lose the realtime screens — the console has `38000` compiled in — but everything else captures.
