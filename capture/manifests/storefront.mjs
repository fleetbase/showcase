// capture/manifests/storefront.mjs
//
// Storefront (mounted at /storefront). StorefrontSeeder seeds a single store (Fleetbase
// Market) and a marketplace network of stores, with catalogs, products, add-ons, customers,
// gateways, order history, a food truck and a push-notification channel — vendored from
// Storefront's own testing seeders.
//
// First contact: the create and edit entries open their forms from list-page buttons whose
// labels are guessed; the run will say which ones need fixing.

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

export default {
    area: 'storefront',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        page('dashboard', 'storefront-dashboard', '/storefront', { extraSettleMs: 3000 }),

        // ── Products ───────────────────────────────────────────────────────────────────
        page('products', 'storefront-products-list', '/storefront/products', { extraSettleMs: 2500 }),
        form('product-create', 'storefront-product-create', '/storefront/products', /new product|^\s*new\s*$/i),
        form('category-create', 'storefront-category-create', '/storefront/products', /new category|create category/i),
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

        // ── Settings ───────────────────────────────────────────────────────────────────
        page('settings', 'storefront-settings', '/storefront/settings'),
        page('settings-api', 'storefront-settings-api', '/storefront/settings/api'),
        page('settings-locations', 'storefront-store-locations-list', '/storefront/settings/locations'),
        page('settings-gateways', 'storefront-payment-gateways-list', '/storefront/settings/gateways'),
        page('settings-notifications', 'storefront-notification-settings', '/storefront/settings/notifications'),
    ],
};
