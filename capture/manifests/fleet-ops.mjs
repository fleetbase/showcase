// capture/manifests/fleet-ops.mjs
//
// What to capture, where it lives on fleetbase.io, and how to know the page is ready.
//
// Filenames are NOT free choices. Sixteen of the entries below reuse a name that already
// exists in fleetbase.io's public/images/screenshots/fleet-ops/, so the pull request shows a
// reviewable before/after on a file the marketing pages already reference. The two that do
// not (fleet-ops-vehicles-list, fleet-ops-driver-details) are genuinely new and will land as
// additions.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// SELECTOR STATUS
//
// The table selectors (TABLE, TABLE_ROW) are CONFIRMED — observed on a running v0.7.57 stack.
// The one-off selectors on the non-table entries (order-config, scheduler, orchestrator,
// map-settings, and the two detail pages) are still PROVISIONAL: they are written as tolerant
// comma-separated lists ending in `main`, so they will match something rather than fail, which
// means a wrong one produces a poorly-framed screenshot instead of an error. Confirm each with
//
//     npm run capture -- --only <id> --out ./out
//
// and tighten it. The console ships no data-test-id attributes at all (checked), so these have
// to be structural.
//
// NOT YET CAPTURED, and deliberately absent rather than guessed:
//
//   fleet-ops-fuel-reports-list  /fleet-ops/manage/fuel-reports renders no table and no
//   fleet-ops-issues-list        /fleet-ops/manage/issues        heading on v0.7.57 — the
//                                route resolves but the screen is empty, so the path is
//                                wrong or the feature moved. The API does serve
//                                /int/v1/fuel-reports and /int/v1/issues with 2 rows each,
//                                so the fixtures exist; only the console path is unknown.
//   fleet-ops-order-details      /fleet-ops/<public_id> redirects to the orders index, so
//                                this would have published the list under a details name.
//   fleet-ops-orchestrator-1     Captures non-deterministically: its `main` fallback selector
//                                lets the capture land before the metrics load, and the file
//                                swung between 56 KB and 158 KB across runs. Needs a real
//                                waitFor on whatever it loads before it can be trusted — a
//                                flaky entry means a noise pull request on some releases.
//
// `expectRows` is the load-bearing half of every list entry. A stale `waitFor` fails loudly; a
// table that renders empty because the seeder did not populate that resource screenshots as a
// perfectly successful run, and the empty state ships to the website.
// ─────────────────────────────────────────────────────────────────────────────────────────

// Both confirmed against a real v0.7.57 console, not guessed.
//
// TABLE is present whether or not the table has data, so it is the right thing to WAIT on —
// waiting on a row instead would time out on an empty table and report it as a stale
// selector rather than as missing fixtures.
const TABLE = '.next-table-wrapper';

// `:not(.next-table-empty-state-row)` is load-bearing, and the reason is a trap worth naming.
// When an ember-ui table has no data it still renders one <tr>, of class
// next-table-empty-state-row, containing the "nothing here" artwork. A plain
// `tbody tr` selector matches it — so an expectRows check of `min: 1` would PASS on a
// completely empty table and publish a screenshot of the empty state. Excluding it is what
// makes expectRows mean what it says.
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';

export default {
    area: 'fleet-ops',

    // Dark owns the unsuffixed filename because the ~150 files already published are dark
    // (measured: mean luminance 29.7/255 on fleet-ops-drivers-list.webp). Light variants get
    // a -light.webp suffix, which is purely additive — no existing reference changes.
    defaultTheme: 'dark',
    themes: ['dark', 'light'],

    // 1440x900 at deviceScaleFactor 2 is 2880x1800, the exact dimensions of every file
    // currently in the library, and the same logical viewport console/testem.js uses.
    viewport: { width: 1440, height: 900 },

    entries: [
        // ── Operations ──────────────────────────────────────────────────────────────────
        {
            id: 'orders-table-list',
            file: 'fleet-ops-orders-table-list',
            // operations has path '/' and orders has path '/' inside it, so the orders index
            // is the engine root.
            url: '/fleet-ops',
            // The orders index opens on the MAP view — the table is in the DOM but hidden, so
            // waiting on it directly times out. The published filename says "table list", so
            // switch views first. Map / Table / Board are the three toggles.
            waitFor: '.leaflet-container, main',
            interact: async (page) => {
                await page.getByRole('button', { name: 'Table' }).first().click();
            },
            waitForAfterInteract: TABLE,
            expectRows: { selector: TABLE_ROW, min: 7 },
            // Rows stream in after the view switch, and the two-identical-frames check can
            // land inside a pause in that streaming. Measured: without this, the light variant
            // differed between two otherwise identical runs.
            extraSettleMs: 2000,
        },
        {
            id: 'order-config-details',
            file: 'fleet-ops-order-config-details',
            url: '/fleet-ops/order-config',
            waitFor: '.order-config-container, [data-test-id="order-config"], main',
            extraSettleMs: 400,
        },
        {
            id: 'service-rate-list',
            file: 'fleet-ops-service-rate-list',
            url: '/fleet-ops/service-rates',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
        },
        {
            id: 'scheduler',
            file: 'fleet-ops-scheduler',
            url: '/fleet-ops/scheduler',
            // No map here, despite what the name suggests — verified on v0.7.57, the scheduler
            // renders zero .leaflet-container elements. An earlier version declared
            // `map: { settleTiles: true }` and hung for the full 45s timeout waiting on tiles
            // that never existed.
            waitFor: '.scheduler-container, main',
            extraSettleMs: 750,
        },

        // ── Management ──────────────────────────────────────────────────────────────────
        {
            id: 'drivers-list',
            file: 'fleet-ops-drivers-list',
            url: '/fleet-ops/manage/drivers',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
        },
        {
            id: 'vehicles-list',
            // No published file under this name today — this entry adds one.
            file: 'fleet-ops-vehicles-list',
            url: '/fleet-ops/manage/vehicles',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 25 },
        },
        {
            id: 'fleets-list',
            file: 'fleet-ops-fleets-list',
            url: '/fleet-ops/manage/fleets',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 9 },
        },
        {
            id: 'places-list',
            file: 'fleet-ops-places-list',
            url: '/fleet-ops/manage/places',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 6 },
        },
        {
            id: 'contacts-list',
            file: 'fleet-ops-contacts-list',
            url: '/fleet-ops/manage/contacts',
            waitFor: TABLE,
            // Four `contact`-type fixtures. The customers are on a sibling tab and do not
            // count towards this table.
            expectRows: { selector: TABLE_ROW, min: 4 },
        },
        {
            id: 'vendors-list',
            file: 'fleet-ops-vendors-list',
            url: '/fleet-ops/manage/vendors',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
        },

        // ── Connectivity and maintenance ────────────────────────────────────────────────
        {
            id: 'devices-list',
            file: 'fleet-ops-devices-list',
            url: '/fleet-ops/connectivity/devices',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
        },
        {
            id: 'maintenance-schedules',
            file: 'fleet-ops-maintenance-schedules',
            url: '/fleet-ops/maintenance/schedules',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 1 },
        },

        // ── Settings ────────────────────────────────────────────────────────────────────
        {
            id: 'map-settings',
            file: 'fleet-ops-map-settings',
            url: '/fleet-ops/settings/map',
            waitFor: 'form, .settings-container, main',
            extraSettleMs: 300,
        },

        // ── Detail pages ────────────────────────────────────────────────────────────────
        // These are why src/resolve.mjs exists. The seeders assign a fresh uuid to every
        // record on every run, so the public_id in the URL is only knowable at runtime; what
        // is stable is the name the seeder sets deliberately.
        {
            id: 'driver-details',
            file: 'fleet-ops-driver-details',
            resolve: {
                resource: 'drivers',
                query: 'Amara Chen',
                match: { field: 'name', value: 'Amara Chen' },
            },
            url: ({ publicId }) => `/fleet-ops/manage/drivers/${publicId}`,
            // Confirmed: the details view renders inside the management layout, not an
            // overlay panel — no .overlay-panel-body exists on v0.7.57.
            waitFor: '.next-table-wrapper, main',
            extraSettleMs: 500,
        },
    ],
};
