// capture/manifests/storefront.mjs
//
// Storefront (mounted at /storefront). StorefrontSeeder seeds a single store (Fleetbase
// Market) and a marketplace network of stores, with catalogs, products, add-ons, customers,
// gateways, order history, a food truck and a push-notification channel — vendored from
// Storefront's own testing seeders.
//
// Two sets of entries, chosen by the Storefront engine version the release ships (see
// src/engines.mjs): LEGACY for the console before v0.4.25, V0425 for the redesign that came with
// it — a store/network context switcher, a full section per network under
// /storefront/networks/<id>, and new store pages. File names carry over where the screen does,
// so the site's references keep working when the release lands.

const TABLE = '.next-table-wrapper';
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';
const MAIN = 'main';
const FORM = '.next-content-overlay.is-open .next-content-overlay-panel, .modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay)';
const PANEL_OR_MAIN = '.next-content-overlay.is-open .next-content-overlay-panel, main';

const page = (id, file, url, extra = {}) => ({ id, file, url, waitFor: MAIN, extraSettleMs: 2000, ...extra });
const click = (name) => async (p) => {
    await p.getByRole('button', { name }).first().click();
};
const form = (id, file, url, name) => ({ id, file, url, waitFor: MAIN, interact: click(name), waitForAfterInteract: FORM, extraSettleMs: 1200 });
// A seeded row's edit modal, opened from the anchor in its name cell.
const rowEditor = (id, file, url, rowName) => ({
    id,
    file,
    url,
    waitFor: TABLE,
    interact: async (p) => {
        await p.locator(TABLE_ROW).filter({ hasText: rowName }).first().locator('a').first().click();
    },
    waitForAfterInteract: FORM,
    extraSettleMs: 1200,
});

const before0425 = (entry) => ({ ...entry, requires: { 'storefront-engine': '<0.4.25' } });
const from0425 = (entry) => ({ ...entry, requires: { 'storefront-engine': '>=0.4.25' } });

const LEGACY = [
    page('dashboard', 'storefront-dashboard', '/storefront', { extraSettleMs: 3000 }),

        // ── Products ───────────────────────────────────────────────────────────────────
        page('products', 'storefront-products-list', '/storefront/products', { extraSettleMs: 2500 }),
        {
            // "New" stays disabled until a category is selected.
            id: 'product-create',
            file: 'storefront-product-create',
            url: '/storefront/products',
            waitFor: MAIN,
            interact: async (p) => {
                await p.locator('.storefront-product-category', { hasText: 'Fresh Produce' }).first().click();
                await p.getByRole('button', { name: /^\s*new\s*$/i }).first().click();
            },
            waitForAfterInteract: FORM,
            extraSettleMs: 1200,
        },
        {
            // The Orchard Fruit Box: photo, variants, add-ons and a category, so the editor
            // shows every section filled in.
            id: 'product-edit',
            file: 'storefront-product-edit',
            // Not addressed by URL: /storefront/products/<public_id> is ambiguous between the
            // edit route and the category route (both one dynamic segment), and a cold load
            // resolves it as a category. Clicking the card's edit action routes by name.
            url: '/storefront/products',
            waitFor: '.storefront-product-card',
            interact: async (p) => {
                await p.locator('.storefront-product-card__quick-actions a[aria-label="Edit Orchard Fruit Box"]').first().click();
            },
            waitForAfterInteract: '.next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 2000,
        },
        {
            // The icon-only + in the category sidebar's header.
            id: 'category-create',
            file: 'storefront-category-create',
            url: '/storefront/products',
            waitFor: '.storefront-product-sidebar',
            interact: async (p) => {
                await p.locator('.storefront-product-sidebar__header button').first().click();
            },
            waitForAfterInteract: FORM,
            extraSettleMs: 1200,
        },
        form('product-addons', 'storefront-product-addons', '/storefront/products', /add-?ons/i),
        form('products-import', 'storefront-products-import', '/storefront/products', /import/i),

        // ── Catalogs, customers, orders ────────────────────────────────────────────────
        page('catalogs', 'storefront-catalogs-list', '/storefront/catalogs'),
        form('catalog-create', 'storefront-catalog-create', '/storefront/catalogs', /new catalog|^\s*new\s*$/i),
        { id: 'customers', file: 'storefront-customers-list', url: '/storefront/customers', waitFor: TABLE, expectRows: { selector: TABLE_ROW, min: 3 }, extraSettleMs: 1000 },
        { id: 'orders', file: 'storefront-orders-list', url: '/storefront/orders', waitFor: TABLE, expectRows: { selector: TABLE_ROW, min: 5 }, extraSettleMs: 1000 },

        // ── Networks ───────────────────────────────────────────────────────────────────
        page('networks', 'storefront-networks-list', '/storefront/networks'),
        form('network-create', 'storefront-network-create', '/storefront/networks', /new network|^\s*new\s*$/i),
        {
            id: 'network-details',
            file: 'storefront-network-details',
            fromSeed: true,
            url: ({ publicId }) => `/storefront/networks/${publicId}/stores`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 2000,
        },
        {
            id: 'network-invite-stores',
            file: 'storefront-network-invite-stores',
            fromSeed: true,
            url: ({ publicId }) => `/storefront/networks/${publicId}/stores`,
            waitFor: PANEL_OR_MAIN,
            interact: click(/invite/i),
            waitForAfterInteract: FORM,
            extraSettleMs: 1200,
        },

        // ── Food trucks and promotions ─────────────────────────────────────────────────
        page('food-trucks', 'storefront-food-trucks-list', '/storefront/food-trucks', { extraSettleMs: 2500 }),
        form('food-truck-create', 'storefront-food-truck-create', '/storefront/food-trucks', /new food truck|^\s*new\s*$/i),
        page('push-notifications', 'storefront-push-notifications', '/storefront/promotions/push-notifications'),

        // ── Promotions, campaigns, segments (seeded by SeedsStorefrontFixtures::seedMarketing) ──
        // Lists are Fleetbase Market's own: four promotions in every state, two segments, a sent,
        // a scheduled and a draft campaign. Edit forms open by clicking a row's name.
        { id: 'promotions', file: 'storefront-promotions-list', url: '/storefront/promotions', waitFor: TABLE, expectRows: { selector: TABLE_ROW, min: 3 }, extraSettleMs: 1500 },
        form('promotion-create', 'storefront-promotion-create', '/storefront/promotions', /new promotion/i),
        rowEditor('promotion-edit', 'storefront-promotion-edit', '/storefront/promotions', 'Coffee hour'),
        { id: 'campaigns', file: 'storefront-campaigns-list', url: '/storefront/promotions/campaigns', waitFor: TABLE, expectRows: { selector: TABLE_ROW, min: 3 }, extraSettleMs: 1500 },
        rowEditor('campaign-edit', 'storefront-campaign-edit', '/storefront/promotions/campaigns', 'Harvest week'),
        { id: 'segments', file: 'storefront-segments-list', url: '/storefront/promotions/segments', waitFor: TABLE, expectRows: { selector: TABLE_ROW, min: 2 }, extraSettleMs: 1500 },
        rowEditor('segment-edit', 'storefront-segment-edit', '/storefront/promotions/segments', 'Regulars'),

        // ── Settings ───────────────────────────────────────────────────────────────────
        page('settings', 'storefront-settings', '/storefront/settings'),
        page('settings-api', 'storefront-settings-api', '/storefront/settings/api'),
        page('settings-locations', 'storefront-store-locations-list', '/storefront/settings/locations'),
        page('settings-gateways', 'storefront-payment-gateways-list', '/storefront/settings/gateways'),
        page('settings-notifications', 'storefront-notification-settings', '/storefront/settings/notifications'),
].map(before0425);

// ── Storefront v0.4.25 ─────────────────────────────────────────────────────────────────────────
// Store context is the default (the console opens on the store); a URL under
// /storefront/networks/<id> switches the console to that network, menu and all. Detail ids come
// from StorefrontSeeder::v0425Fixtures (`fromSeed`).
const OVERLAY = '.next-content-overlay.is-open .next-content-overlay-panel';
const NETWORK_PAGE = '[data-test-storefront-network-page], [data-test-network-overview]';
const seeded = (id, file, url, extra = {}) => ({ id, file, fromSeed: true, url, waitFor: MAIN, extraSettleMs: 2000, ...extra });
const network = (path) => ({ publicId }) => `/storefront/networks/${publicId}${path}`;
const tab = (hook) => async (p) => {
    await p.locator(`[data-test-status-tab="${hook}"]`).first().click();
};

const V0425 = [
    // ── Store context ──────────────────────────────────────────────────────────────────────────
    page('v-dashboard', 'storefront-dashboard', '/storefront', { waitFor: '[data-test-dashboard-heading], main', extraSettleMs: 3000 }),
    {
        // The store/network switcher: the sidebar button opens a menu of stores and networks.
        id: 'v-context-switcher',
        file: 'storefront-switcher-menu',
        url: '/storefront',
        waitFor: '[data-test-store-selector-trigger]',
        interact: async (p) => {
            await p.locator('[data-test-store-selector-trigger]').first().click();
        },
        waitForAfterInteract: '.storefront-switcher-menu',
        extraSettleMs: 1200,
    },
    page('v-orders', 'storefront-orders-list', '/storefront/orders', { waitFor: '[data-test-orders-card]', extraSettleMs: 2000 }),
    seeded('v-order-details', 'storefront-order-details', ({ publicId }) => `/storefront/orders/${publicId}`, { id: 'order-details', waitFor: OVERLAY }),
    page('v-order-create', 'storefront-order-create', '/storefront/orders/new', { waitFor: OVERLAY, extraSettleMs: 2000 }),
    page('v-products', 'storefront-products-list', '/storefront/products', { extraSettleMs: 2500 }),
    page('v-product-create', 'storefront-product-create', '/storefront/products/new', { waitFor: OVERLAY, extraSettleMs: 1500 }),
    seeded('v-product-edit', 'storefront-product-edit', ({ publicId }) => `/storefront/products/${publicId}`, { id: 'product-details', waitFor: OVERLAY }),
    page('v-catalogs', 'storefront-catalogs-list', '/storefront/catalogs', { waitFor: '[data-test-catalogs-header], main' }),
    seeded('v-catalog-edit', 'storefront-catalog-edit', ({ publicId }) => `/storefront/catalogs/${publicId}`, { id: 'catalog-editor', waitFor: '[data-test-catalog-editor]' }),
    page('v-customers', 'storefront-customers-list', '/storefront/customers', { waitFor: '[data-test-customers-card]' }),
    seeded('v-customer-details', 'storefront-customer-details', ({ publicId }) => `/storefront/customers/${publicId}`, { id: 'customer-details', waitFor: OVERLAY }),
    page('v-food-trucks', 'storefront-food-trucks-list', '/storefront/food-trucks', { waitFor: '[data-test-trucks-header]', extraSettleMs: 2500 }),
    {
        // The trucks page's Map button: trucks with a reported position on a map.
        id: 'v-food-trucks-map',
        file: 'storefront-food-trucks-map',
        url: '/storefront/food-trucks',
        waitFor: '[data-test-trucks-map]',
        interact: async (p) => {
            await p.locator('[data-test-trucks-map]').first().click();
        },
        waitForAfterInteract: '[data-test-trucks-map-view]',
        map: { settleTiles: true },
        extraSettleMs: 2000,
    },
    page('v-promotions', 'storefront-promotions-list', '/storefront/promotions', { waitFor: '[data-test-promotions-header]', extraSettleMs: 1500 }),
    page('v-campaigns', 'storefront-campaigns-list', '/storefront/promotions/campaigns', { waitFor: '[data-test-promotions-header]', extraSettleMs: 1500 }),
    page('v-segments', 'storefront-segments-list', '/storefront/promotions/segments', { waitFor: '[data-test-promotions-header]', extraSettleMs: 1500 }),
    page('v-redemptions', 'storefront-redemptions-list', '/storefront/promotions/redemptions', { waitFor: '[data-test-promotions-header]', extraSettleMs: 1500 }),
    page('v-push-notifications', 'storefront-push-notifications', '/storefront/promotions/push-notifications'),
    page('v-settings', 'storefront-settings', '/storefront/settings', { waitFor: '[data-test-settings-shell], [data-test-settings-header], main' }),
    page('v-settings-api', 'storefront-settings-api', '/storefront/settings/api', { waitFor: '[data-test-settings-shell]' }),
    page('v-settings-locations', 'storefront-store-locations-list', '/storefront/settings/locations', { waitFor: '[data-test-settings-shell]' }),
    page('v-settings-gateways', 'storefront-payment-gateways-list', '/storefront/settings/gateways', { waitFor: '[data-test-settings-shell]' }),
    page('v-settings-notifications', 'storefront-notification-settings', '/storefront/settings/notifications', { waitFor: '[data-test-settings-shell]' }),

    // ── Network context ────────────────────────────────────────────────────────────────────────
    page('v-networks', 'storefront-networks-list', '/storefront/networks'),
    seeded('network-overview', 'storefront-network-details', network(''), { waitFor: '[data-test-network-overview]', extraSettleMs: 2500 }),
    seeded('network-stores', 'storefront-network-stores-list', network('/stores'), { waitFor: NETWORK_PAGE }),
    seeded('network-stores-approval', 'storefront-network-stores-list-pending', network('/stores'), {
        waitFor: '[data-test-status-tab="approval"]',
        interact: tab('approval'),
        waitForAfterInteract: '[data-test-status-tab="approval"][aria-selected="true"]',
    }),
    seeded('network-stores-invite', 'storefront-network-invite-stores', network('/stores'), {
        waitFor: NETWORK_PAGE,
        interact: async (p) => {
            await p.getByRole('button', { name: /invite stores/i }).first().click();
        },
        waitForAfterInteract: '[data-test-invite-panel]',
    }),
    seeded('network-store-details', 'storefront-network-store-details', ({ publicId }) => `/storefront/networks/${publicId}`, { waitFor: '[data-test-network-store], [data-test-network-store-header]' }),
    seeded('network-orders', 'storefront-network-orders-list', network('/orders'), { waitFor: '[data-test-network-orders-card]' }),
    seeded('network-order-create', 'storefront-network-order-create', network('/orders/new'), { waitFor: OVERLAY }),
    seeded('network-trucks', 'storefront-network-trucks-list', network('/trucks'), { waitFor: '[data-test-trucks-header]' }),
    seeded('network-customers', 'storefront-network-customers-list', network('/customers'), { waitFor: '[data-test-network-customers-card]' }),
    seeded('network-categories', 'storefront-network-categories-list', network('/categories'), { waitFor: '[data-test-network-categories-card]' }),
    seeded('network-promotions', 'storefront-network-promotions-list', network('/promotions'), { waitFor: '[data-test-promotions-header]' }),
    seeded('network-campaigns', 'storefront-network-campaigns-list', network('/promotions/campaigns'), { waitFor: '[data-test-promotions-header]' }),
    seeded('network-segments', 'storefront-network-segments-list', network('/promotions/segments'), { waitFor: '[data-test-promotions-header]' }),
    seeded('network-redemptions', 'storefront-network-redemptions-list', network('/promotions/redemptions'), { waitFor: '[data-test-promotions-header]' }),
    seeded('network-settings', 'storefront-network-settings', network('/settings'), { waitFor: '[data-test-network-settings-shell], [data-test-network-settings]' }),
].map(from0425);

export default {
    area: 'storefront',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [...LEGACY, ...V0425],
};
