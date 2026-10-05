// capture/manifests/extensions.mjs
//
// The extension registry (registry-bridge, mounted at /extensions). No fixtures: the catalogue
// is what registry:init synced from the registry during provisioning, and the installed list is
// what the release ships preinstalled. Both are real content, so these screens change when the
// public catalogue does — which is the point of refreshing them.
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
        { id: 'browse', file: 'extensions-browse', url: '/extensions', waitFor: GRID, extraSettleMs: 3000 },
        {
            id: 'install',
            file: 'extensions-install',
            url: '/extensions',
            waitFor: GRID,
            interact: async (page) => {
                await page.locator(`${GRID} .card-img-lg`).first().click();
            },
            waitForAfterInteract: DIALOG,
            extraSettleMs: 1500,
        },
        { id: 'installed', file: 'extensions-installed-list', url: '/extensions/installed', waitFor: MAIN, extraSettleMs: 2000 },
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
        { id: 'purchased', file: 'extensions-purchased-list', url: '/extensions/purchased', waitFor: MAIN, extraSettleMs: 2000 },
    ],
};
