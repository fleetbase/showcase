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
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 10 },
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
            expectRows: { selector: TABLE_ROW, min: 1 },
        },
        {
            id: 'scheduler',
            file: 'fleet-ops-scheduler',
            url: '/fleet-ops/scheduler',
            waitFor: '.leaflet-container, .scheduler-container, main',
            // The scheduler renders a Leaflet map; tiles arrive asynchronously and fade in.
            map: { settleTiles: true },
            extraSettleMs: 750,
        },
        {
            id: 'orchestrator',
            file: 'fleet-ops-orchestrator-1',
            url: '/fleet-ops/orchestrator',
            waitFor: '.orchestrator-container, main',
            extraSettleMs: 600,
        },

        // ── Management ──────────────────────────────────────────────────────────────────
        {
            id: 'drivers-list',
            file: 'fleet-ops-drivers-list',
            url: '/fleet-ops/manage/drivers',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 8 },
        },
        {
            id: 'vehicles-list',
            // No published file under this name today — this entry adds one.
            file: 'fleet-ops-vehicles-list',
            url: '/fleet-ops/manage/vehicles',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 6 },
        },
        {
            id: 'fleets-list',
            file: 'fleet-ops-fleets-list',
            url: '/fleet-ops/manage/fleets',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
        },
        {
            id: 'places-list',
            file: 'fleet-ops-places-list',
            url: '/fleet-ops/manage/places',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 5 },
        },
        {
            id: 'contacts-list',
            file: 'fleet-ops-contacts-list',
            url: '/fleet-ops/manage/contacts',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
        },
        {
            id: 'vendors-list',
            file: 'fleet-ops-vendors-list',
            url: '/fleet-ops/manage/vendors',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
        },
        {
            id: 'fuel-reports-list',
            file: 'fleet-ops-fuel-reports-list',
            url: '/fleet-ops/manage/fuel-reports',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
        },
        {
            id: 'issues-list',
            file: 'fleet-ops-issues-list',
            url: '/fleet-ops/manage/issues',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
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
            expectRows: { selector: TABLE_ROW, min: 2 },
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
            waitFor: '.overlay-panel-body, [data-test-id="driver-details"], main',
            extraSettleMs: 500,
        },
        {
            id: 'order-details',
            file: 'fleet-ops-order-details',
            resolve: {
                // FLT-1004 is the demo seeder's "started" order: it has a driver, a vehicle and
                // a live route, so the detail page renders with something on the map. See
                // Demo\OrdersSeeder::INTERNAL_IDS — changing that table breaks this lookup.
                resource: 'orders',
                query: 'FLT-1004',
                match: { field: 'internal_id', value: 'FLT-1004' },
            },
            // orders sits at the engine root, so its details route is /fleet-ops/<public_id>.
            url: ({ publicId }) => `/fleet-ops/${publicId}`,
            waitFor: '.overlay-panel-body, [data-test-id="order-details"], main',
            map: { settleTiles: true },
            extraSettleMs: 750,
        },
    ],
};
