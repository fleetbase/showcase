// capture/manifests/extensions.mjs
//
// The Extensions screens (registry-bridge, mounted at /extensions). Two sets, because
// registry-bridge 0.3.0 (Fleetbase v0.9.0) replaced every screen:
//
//   before 0.3.0   The marketplace an instance kept in its own database: a card grid, an
//                  install modal. Seeded by seeders/Extensions/MarketplaceSeeder.php.
//   0.3.0 onwards  Discover, category and extension pages, Installed, Updates and Purchased,
//                  read from the Registry Service. In this stack that is the stand-in at
//                  docker/registry/server.mjs serving seeders/Extensions/catalog.json;
//                  RegistryServiceSeeder registers the instance with it. The catalogue has
//                  no core modules (Customer Portal, Pallet, AI, Valhalla, VROOM): those
//                  ship with Fleetbase and are not installed from the registry.
//
// Where a new screen replaces an old one it keeps the old file name (extensions-browse,
// extensions-install, extensions-installed-list, extensions-uninstall), so the pages on
// fleetbase.io that show it pick up the new screen without an edit.
//
// Selectors are classes, never data-test-* attributes: the published console is a production
// build, and those are stripped from it.

const GRID = '.card-grid-container';
const DIALOG = '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay)';

const before030 = (entry) => ({ ...entry, requires: { 'registry-bridge-engine': '<0.3.0' } });
const from030 = (entry) => ({ ...entry, requires: { 'registry-bridge-engine': '>=0.3.0' } });

// ── 0.3.0 onwards ────────────────────────────────────────────────────────────────────────

/** An extension as a card (the default view) or as a list row. */
const LISTING = '.rb-grid .rb-tile, .rb-list .rb-row';
/** A row of Installed or Purchased. */
const LIBRARY_ROW = '.rb-lib-list .rb-lib-row';
/**
 * A button that can be pressed: Get, Buy or Update. Every button turns secondary and disabled
 * when the instance is not registered or the API cannot tell that an extension will load, so a
 * count of these is what separates a working catalogue from a full page of "Not connected".
 */
const ACTION = '.rb-install-btn.is-primary';
const EXTENSION_PAGE = '.rb-extension-page .rb-extension-head';

/** One extension's page, by slug: the catalogue's slugs are fixed, so no id has to be resolved. */
const extensionPage = (id, file, slug, extra = {}) => ({ id, file, url: `/extensions/extension/${slug}`, waitFor: EXTENSION_PAGE, extraSettleMs: 1500, ...extra });

/** Stop when the page does not say `text`: the screenshot would show a different state than its name. */
const expectText = (text) => async (page) => {
    await page.getByText(text).first().waitFor({ state: 'visible', timeout: 15_000 });
};

const STORE = [
    // Discover: the featured banner, the category rail and the shelves. catalog.json has 24
    // extensions; the shelves show eight each.
    { id: 'discover', file: 'extensions-browse', url: '/extensions', waitFor: '.rb-featured', expectRows: { selector: ACTION, min: 8 }, extraSettleMs: 3000 },
    {
        // A category, as a list: the view that shows the publisher and whether each extension
        // works with this Fleetbase. The choice is remembered in localStorage, so it is undone
        // afterwards or every later screen would be a list too.
        id: 'category',
        file: 'extensions-category-list',
        url: '/extensions/telematics',
        waitFor: '.rb-category-head',
        interact: async (page) => {
            await page.locator('.rb-view-toggle-btn').nth(1).click();
        },
        waitForAfterInteract: '.rb-list .rb-row',
        expectRows: { selector: '.rb-list .rb-row', min: 5 },
        cleanup: async (page) => {
            await page.evaluate(() => window.localStorage.removeItem('registry-bridge:view'));
        },
        extraSettleMs: 1500,
    },
    // Search results, with the sort and filter toolbar. "orders" matches the e-commerce and
    // ERP connectors and the courier networks.
    { id: 'search', file: 'extensions-search-list', url: '/extensions?query=orders', waitFor: '.rb-results-head', expectRows: { selector: LISTING, min: 4 }, extraSettleMs: 2000 },

    // An extension's page. flespi is installed and bought, and the only listing with
    // screenshots, so this is the fullest page: Open, the screenshots, what you get.
    extensionPage('extension-details', 'extensions-extension-details', 'flespi', { expectRows: { selector: '.rb-shots img', min: 3 } }),
    // Free and not installed: the page a customer installs from.
    extensionPage('extension-install', 'extensions-install', 'shopify', { expectRows: { selector: `.rb-extension-actions ${ACTION}`, min: 1 }, interact: expectText(/^\s*Get\b/) }),
    // Paid and not bought: the Buy button with its price.
    extensionPage('extension-paid', 'extensions-extension-details-paid', 'billing', { expectRows: { selector: `.rb-extension-actions ${ACTION}`, min: 1 }, interact: expectText(/Buy\s*·/) }),
    // Its newest release needs a newer Fleetbase than any release this stack can run, so the
    // page explains why it cannot be installed yet.
    extensionPage('extension-blocked', 'extensions-extension-details-blocked', 'cold-chain-monitor', { interact: expectText("Can't install yet") }),
    {
        // The What's new tab of an extension with an update waiting: release notes and versions.
        ...extensionPage('extension-whats-new', 'extensions-extension-whats-new', 'lalamove'),
        interact: async (page) => {
            await page.locator('.rb-tab', { hasText: /what.s new/i }).click();
        },
        waitForAfterInteract: '.rb-version-list',
    },

    { id: 'installed-list', file: 'extensions-installed-list', url: '/extensions/installed', waitFor: '.rb-lib-list', expectRows: { selector: LIBRARY_ROW, min: 5 }, extraSettleMs: 2000 },
    {
        // The confirmation that opens beside a row. Nothing is confirmed: an uninstall would
        // change what every later run sees.
        id: 'uninstall-confirm',
        file: 'extensions-uninstall',
        url: '/extensions/installed',
        waitFor: '.rb-lib-list',
        interact: async (page) => {
            await page.locator(`${LIBRARY_ROW} .rb-icon-btn.is-danger`).first().click();
        },
        waitForAfterInteract: '.rb-lib-row .rb-popover',
        extraSettleMs: 1000,
    },
    { id: 'updates', file: 'extensions-updates-list', url: '/extensions/updates', waitFor: '.rb-update-card', expectRows: { selector: '.rb-update-card', min: 2 }, extraSettleMs: 2000 },
    { id: 'purchased', file: 'extensions-purchased-list', url: '/extensions/purchased', waitFor: '.rb-lib-list', expectRows: { selector: LIBRARY_ROW, min: 2 }, extraSettleMs: 2000 },
].map(from030);

// ── Before 0.3.0 ─────────────────────────────────────────────────────────────────────────
//
// Cards are Layout::Resource::Card inside .card-grid-container; a click opens the
// extension-details modal (the install screen).

const MARKETPLACE = [
    // MarketplaceSeeder publishes ten; an empty catalogue must fail, not screenshot as an
    // empty state over the published image.
    { id: 'browse', file: 'extensions-browse', url: '/extensions', waitFor: GRID, expectRows: { selector: `${GRID} .card-img-lg`, min: 8 }, extraSettleMs: 3000 },
    {
        id: 'install',
        file: 'extensions-install',
        url: '/extensions',
        waitFor: GRID,
        interact: async (page) => {
            // Pallet: free and not installed, so the modal shows the Install flow.
            await page.locator(`${GRID} .card-img-lg[alt="Pallet"]`).first().click();
            // The dialog frame appears before its content; one of two identical runs shot
            // the empty frame.
            await page.getByText('Inventory & warehouse management').last().waitFor({ state: 'visible' });
        },
        waitForAfterInteract: DIALOG,
        extraSettleMs: 1500,
    },
    { id: 'installed', file: 'extensions-installed-list', url: '/extensions/installed', waitFor: '.extension-card-grid-container', expectRows: { selector: '.extension-card-grid-container img', min: 5 }, extraSettleMs: 2000 },
    {
        id: 'uninstall',
        file: 'extensions-uninstall',
        url: '/extensions/installed',
        waitFor: '.extension-card-grid-container',
        interact: async (page) => {
            await page
                .getByRole('button', { name: /uninstall/i })
                .first()
                .click();
        },
        waitForAfterInteract: DIALOG,
        extraSettleMs: 1000,
    },
    // extensions-purchased-list is not captured before 0.3.0. That purchased.hbs passes
    // @extension to <ExtensionCard>, which reads @resource, so every purchased card renders
    // blank ("Free", no name). The 0.3.0 Purchased screen above does not have the fault.
].map(before030);

export default {
    area: 'extensions',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [...STORE, ...MARKETPLACE],
};
