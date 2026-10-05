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
// A Layout::Resource::Panel (detail/edit views) once it has slid open.
const PANEL = '.next-content-overlay.is-open .next-content-overlay-panel';

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
        //
        // The three entries the monorepo README leads with. Their selectors are PROVISIONAL
        // until confirmed on a running stack (see the header).
        {
            id: 'live-orders-panel',
            file: 'fleet-ops-orders-map',
            // The orders index opens on the Map view: a Leaflet map with the active orders
            // listed in the side panel. The panel is NOT open by default — its state lives in
            // the app-cache localStorage bucket, which the capture wipes on every boot for
            // determinism — so open it from the toolbar the way an operator does.
            url: '/fleet-ops',
            waitFor: '.leaflet-container',
            interact: async (page) => {
                await page.locator('#map-toolbar-view-orders-button').click();
            },
            // The Overlay puts `is-open` on its root and the caller's class on the inner
            // panel, so the two never sit on one element.
            waitForAfterInteract: '.next-content-overlay.is-open .orders-panel',
            // The live map is the one surface the engine can render with Google Maps; the
            // Google pass (scripts/capture.sh, with GOOGLE_MAPS_API_KEY) captures this entry
            // again as fleet-ops-live-orders-panel-google.
            map: { settleTiles: true, google: { waitFor: '.google-live-map-container .gm-style' } },
            extraSettleMs: 2500,
        },
        {
            id: 'orders-kanban',
            file: 'fleet-ops-orders-board',
            url: '/fleet-ops',
            waitFor: '.leaflet-container, main',
            // Board view groups orders by activity, one column per step of the order config's
            // flow — which is why the last-mile fixtures exist: five statuses is five thin
            // columns, ten is a board.
            //
            // The board only groups by a config's flow once that config is chosen in its
            // "Select Order Type" filter; with none chosen it shows the generic columns
            // (Created / Dispatched / Started / Completed) with every order in Created.
            interact: async (page) => {
                await page.getByRole('button', { name: 'Board' }).first().click();
                await page.locator('.order-board-type-filter').first().click();
                await page.locator('.ember-power-select-option', { hasText: 'Last-Mile Delivery' }).first().click();
            },
            // A column that only the last-mile flow has. Column titles are
            // titleize(humanize(status)), hence the loose match.
            waitForAfterInteract: 'text=/enroute dropoff/i',
            extraSettleMs: 2500,
        },
        {
            id: 'multi-waypoint-order',
            file: 'fleet-ops-order-details-multi-stop',
            // FLT-2007: picked up, three stops, driver and vehicle assigned, mid-route. The
            // details view is a panel over the map, so the route and the activity timeline
            // both render.
            resolve: {
                resource: 'orders',
                query: 'FLT-2007',
                match: { field: 'internal_id', value: 'FLT-2007' },
            },
            url: ({ publicId }) => `/fleet-ops/${publicId}`,
            // The details panel is an Overlay that slides in once the order resolves; waiting
            // only for the map captured the route with no panel at all. Require the panel to
            // be open so a failed order load is a failed capture, not a wrong one.
            waitFor: '.next-content-overlay.is-open .next-content-overlay-panel',
            // Also captured on Google Maps (the details panel sits over the same live map).
            map: { settleTiles: true, google: {} },
            extraSettleMs: 3000,
        },
        {
            id: 'orders-table-list',
            file: 'fleet-ops-orders-list',
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
            id: 'order-config-activity-flow',
            file: 'fleet-ops-order-config-activity-flow',
            url: '/fleet-ops/order-config',
            waitFor: 'main',
            // Select the seeded Last-Mile Delivery config, then open its Activity Flow tab —
            // the node graph of the ten-step flow OrderConfigSeeder defines.
            //
            // Selectors from the engine template (addon/templates/operations/order-config.hbs):
            // the picker is a DropdownButton with class .order-config-select-button whose
            // items are a.next-dd-item, and the tabs are .fleetops-pill-tabs .ui-tab with the
            // title in a <span>. The tabs do not exist until a config is selected — the first
            // attempt clicked a role=button named "Activity Flow" before selecting anything,
            // and timed out on a tab that was not there.
            interact: async (page) => {
                await page.locator('.order-config-select-button').first().click();
                await page.locator('a.next-dd-item', { hasText: 'Last-Mile Delivery' }).first().click();
                await page
                    .locator('.fleetops-pill-tabs .ui-tab', { hasText: /activity flow/i })
                    .first()
                    .click();
                // The editor opens at scale 1, where a ten-step flow shows three and a half
                // nodes. zoomOut() steps the JointJS paper by 0.2 down to a floor of 0.2;
                // three steps (0.4) shows most of the main path at a size that still reads.
                const zoomOut = page.locator('.activity-flow-zoom-controls button').nth(1);
                await zoomOut.waitFor({ state: 'visible' });
                for (let i = 0; i < 3; i++) await zoomOut.click();
            },
            waitForAfterInteract: '.fleetops-pill-tabs .ui-tab.active',
            extraSettleMs: 2500,
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
            file: 'fleet-ops-maintenance-schedules-list',
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

        // ── Trailers ───────────────────────────────────────────────────────────────────
        // Three docs pages (resources/trailers/*) and no screenshot anywhere: these are the
        // first entries that create images the library never had. TrailerSeeder seeds four
        // trailers, one towed by JUR-07 and carrying equipment.
        {
            id: 'trailers-list',
            file: 'fleet-ops-trailers-list',
            url: '/fleet-ops/manage/trailers',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 4 },
            extraSettleMs: 500,
        },
        {
            id: 'trailer-details',
            file: 'fleet-ops-trailer-details',
            resolve: {
                resource: 'trailers',
                query: 'TRL-01',
                match: { field: 'code', value: 'TRL-01' },
            },
            url: ({ publicId }) => `/fleet-ops/manage/trailers/${publicId}`,
            // A Layout::Resource::Panel over the list, like the order details panel.
            waitFor: '.next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 1500,
        },
        {
            id: 'trailer-connections',
            file: 'fleet-ops-trailer-connections',
            resolve: {
                resource: 'trailers',
                query: 'TRL-01',
                match: { field: 'code', value: 'TRL-01' },
            },
            url: ({ publicId }) => `/fleet-ops/manage/trailers/${publicId}/connections`,
            waitFor: '.next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 1500,
        },
        {
            id: 'trailer-equipment',
            file: 'fleet-ops-trailer-equipment',
            resolve: {
                resource: 'trailers',
                query: 'TRL-01',
                match: { field: 'code', value: 'TRL-01' },
            },
            url: ({ publicId }) => `/fleet-ops/manage/trailers/${publicId}/equipment`,
            waitFor: '.next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 1500,
        },

        // ── Pri-1 batch: screens the docs describe that need no new fixtures ───────────
        // From COVERAGE.md → "Screenshots that do not exist yet". Settings pages regenerate
        // library images; the rest are new names.
        ...['avatars', 'notifications', 'payments', 'routing', 'scheduling', 'orchestrator', 'navigator-app', 'custom-fields', 'telematics'].map((page) => ({
            id: `settings-${page}`,
            file: {
                avatars: 'fleet-ops-avatar-settings',
                notifications: 'fleet-ops-notification-settings',
                payments: 'fleet-ops-payment-settings',
                routing: 'fleet-ops-routing-settings',
                scheduling: 'fleet-ops-scheduling-settings',
                orchestrator: 'fleet-ops-orchestrator-settings',
                'navigator-app': 'fleet-ops-navigator-app-settings',
                'custom-fields': 'fleet-ops-custom-fields-settings',
                telematics: 'fleet-ops-telematics-settings',
            }[page],
            url: `/fleet-ops/settings/${page}`,
            waitFor: 'main form, main .content-panel, main',
            extraSettleMs: 1200,
        })),
        {
            id: 'vehicle-capacity',
            file: 'fleet-ops-vehicle-edit-capacity',
            // resources/vehicles/capacity-and-payload: the edit form's Capacity & Dimensions
            // block. JUR-07, the Hino that tows TRL-01.
            resolve: { resource: 'vehicles', query: 'GBB-1007', match: { field: 'plate_number', value: 'GBB-1007' } },
            url: ({ publicId }) => `/fleet-ops/manage/vehicles/edit/${publicId}`,
            waitFor: PANEL,
            interact: async (page) => {
                await page.getByText('Capacity & Dimensions', { exact: false }).first().scrollIntoViewIfNeeded();
            },
            extraSettleMs: 1000,
        },
        {
            id: 'place-details',
            file: 'fleet-ops-place-details',
            resolve: { resource: 'places', query: 'Raffles Quay', match: { field: 'name', value: 'Raffles Quay' } },
            url: ({ publicId }) => `/fleet-ops/manage/places/${publicId}`,
            waitFor: PANEL,
            map: { settleTiles: true },
            extraSettleMs: 1500,
        },
        {
            id: 'order-config-new',
            file: 'fleet-ops-order-config-create',
            // operations/order-configurations/order-types: the New Order Config dialog.
            url: '/fleet-ops/order-config',
            waitFor: '.order-config-select-button',
            interact: async (page) => {
                await page
                    .getByRole('button', { name: /new order config/i })
                    .first()
                    .click();
            },
            waitForAfterInteract: '.modal-dialog, .modal, [role="dialog"]:not(.next-content-overlay)',
            extraSettleMs: 1000,
        },
        {
            id: 'order-update-activity',
            file: 'fleet-ops-order-update-activity',
            // operations/orders/order-lifecycle: the next-activity chooser on FLT-2007.
            resolve: { resource: 'orders', query: 'FLT-2007', match: { field: 'internal_id', value: 'FLT-2007' } },
            url: ({ publicId }) => `/fleet-ops/${publicId}`,
            waitFor: PANEL,
            // The panel header collapses its action buttons into one DropdownButton (ember-ui
            // renders an `items` array as an ellipsis trigger), so "Update activity" is not a
            // button on the page until that dropdown is open — the first attempt clicked a
            // button that does not exist and timed out.
            interact: async (page) => {
                await page.locator('.next-content-overlay.is-open .ember-basic-dropdown-trigger').first().click();
                await page
                    .getByText(/update activity/i)
                    .first()
                    .click();
            },
            waitForAfterInteract: '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay)',
            extraSettleMs: 1500,
        },
        {
            id: 'order-new',
            file: 'fleet-ops-order-create',
            // operations/orders/managing-orders. Replaces the hand-made create-order shot.
            url: '/fleet-ops/new',
            waitFor: `${PANEL}, main form`,
            map: { settleTiles: true },
            extraSettleMs: 2000,
        },
        {
            id: 'zones',
            file: 'fleet-ops-zones-map',
            // operations/service-areas-geofences/zones: the live map with the service-area
            // panel open (zones are drawn on the map already; the panel lists them).
            url: '/fleet-ops',
            waitFor: '.leaflet-container',
            interact: async (page) => {
                await page.locator('#map-toolbar-service-areas-button .ember-basic-dropdown-trigger, #map-toolbar-service-areas-button [role="button"]').first().click();
            },
            waitForAfterInteract: '.ember-basic-dropdown-content',
            map: { settleTiles: true },
            extraSettleMs: 2000,
        },
        {
            id: 'work-order-details',
            file: 'fleet-ops-work-order-details',
            resolve: { resource: 'work-orders', query: 'FLT-WO-OPEN', match: { field: 'code', value: 'FLT-WO-OPEN' } },
            url: ({ publicId }) => `/fleet-ops/maintenance/work-orders/${publicId}`,
            waitFor: PANEL,
            extraSettleMs: 1500,
        },
        {
            id: 'part-details',
            file: 'fleet-ops-part-details',
            resolve: { resource: 'parts', query: 'Donaldson P550', match: { field: 'name', value: 'Donaldson P550' } },
            url: ({ publicId }) => `/fleet-ops/maintenance/parts/${publicId}`,
            waitFor: PANEL,
            extraSettleMs: 1500,
        },
        {
            id: 'equipment-details',
            file: 'fleet-ops-equipment-details',
            resolve: { resource: 'equipment', query: 'Carrier X4-7500', match: { field: 'name', value: 'Carrier X4-7500' } },
            url: ({ publicId }) => `/fleet-ops/maintenance/equipment/${publicId}`,
            waitFor: PANEL,
            extraSettleMs: 1500,
        },
        {
            id: 'equipment-list',
            file: 'fleet-ops-equipment-list',
            url: '/fleet-ops/maintenance/equipment',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 5 },
            extraSettleMs: 500,
        },
        {
            id: 'parts-inventory',
            file: 'fleet-ops-parts-list',
            url: '/fleet-ops/maintenance/parts',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
            extraSettleMs: 500,
        },
        {
            id: 'maintenance-log',
            file: 'fleet-ops-maintenances-list',
            url: '/fleet-ops/maintenance/maintenances',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
            extraSettleMs: 500,
        },
        {
            id: 'device-details',
            file: 'fleet-ops-device-details',
            resolve: { resource: 'devices', query: 'DEV-CEN-001', match: { field: 'serial_number', value: 'DEV-CEN-001' } },
            url: ({ publicId }) => `/fleet-ops/connectivity/devices/${publicId}`,
            waitFor: PANEL,
            extraSettleMs: 1500,
        },
        {
            id: 'sensors-list',
            file: 'fleet-ops-sensors-list',
            url: '/fleet-ops/connectivity/sensors',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
            extraSettleMs: 500,
        },
        {
            id: 'telematics-providers',
            file: 'fleet-ops-telematics-list',
            url: '/fleet-ops/connectivity/telematics',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 1 },
            extraSettleMs: 500,
        },
        {
            id: 'fuel-integrations',
            file: 'fleet-ops-fuel-integrations-list',
            // recipes/fuel-provider-integrations: the integrations page (intro/empty state
            // is the honest picture until a provider is connected).
            url: '/fleet-ops/connectivity/fuel-integrations',
            waitFor: 'main',
            extraSettleMs: 1200,
        },
    ],
};
