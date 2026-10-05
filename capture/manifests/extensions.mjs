// capture/manifests/extensions.mjs
//
// The extension registry (registry-bridge, mounted at /extensions). A self-hosted instance's
// Explore page lists only what is published to its own registry — nothing is synced from
// registry.fleetbase.io — so the catalogue, installs and purchases are seeded by
// seeders/Extensions/ExtensionsSeeder.php: the real Fleetbase extensions, in registry-bridge's
// core categories.
//
// Cards are Layout::Resource::Card inside .card-grid-container; a click opens the
// extension-details modal (the install screen).

const MAIN = 'main';
const GRID = '.card-grid-container';
const DIALOG = '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay)';

export default {
    area: 'extensions',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        // ExtensionsSeeder publishes ten; an empty catalogue must fail, not screenshot as an
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
        // extensions-purchased-list is deliberately not captured. registry-bridge's
        // purchased.hbs passes @extension to <ExtensionCard>, which reads @resource, so every
        // purchased card renders blank ("Free", no name) in v0.7.68 — capturing it would
        // replace the published image with a broken one. Restore once that is fixed upstream.
    ],
};
