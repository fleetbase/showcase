// capture/manifests/general.mjs
//
// The console itself rather than any engine: home dashboard, the header trays (notifications,
// chat, locale), navigation, and the user's account and organization settings. The area is
// `general` because that is the fleetbase.io folder these land in; the files are named
// console-* (NAMING.md).
//
// Fixtures: GeneralSeeder adds notifications and a chat channel. Everything else renders from
// what the other areas seed. The onboarding screens are not here — they need an organization
// that does not exist yet, which is a separate pass before seeding.
//
// Header controls are ember-ui components with stable classes (layout/header.hbs):
// .notification-tray, .chat-tray, .locale-selector-tray, .snm-more-btn, .snm-customise-btn,
// .sidebar-toggle-button.

const TABLE = '.next-table-wrapper';
const MAIN = 'main';
const OPEN_DROPDOWN = '.ember-basic-dropdown-content';

export default {
    area: 'general',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        // ── Dashboard ──────────────────────────────────────────────────────────────────
        { id: 'dashboard', file: 'console-dashboard', url: '/', waitFor: MAIN, extraSettleMs: 2500 },
        {
            id: 'dashboard-switch',
            file: 'console-dashboard-switch',
            url: '/',
            waitFor: MAIN,
            interact: async (page) => {
                // The first DropdownButton in the dashboard header carries the dashboard's name.
                await page.locator('main .ember-basic-dropdown-trigger').first().click();
            },
            waitForAfterInteract: OPEN_DROPDOWN,
            extraSettleMs: 1200,
        },
        {
            id: 'dashboard-add-widgets',
            file: 'console-dashboard-add-widgets',
            url: '/',
            waitFor: MAIN,
            interact: async (page) => {
                // "Add widgets" exists only on a custom dashboard; the system dashboard's menu
                // offers just "Create new Dashboard". Switch to the one GeneralSeeder created.
                await page.locator('main .ember-basic-dropdown-trigger').nth(0).click();
                await page.locator('.next-dd-item', { hasText: 'Operations Overview' }).first().click();
                await page.getByText('Operations Overview', { exact: true }).first().waitFor({ state: 'visible' });
                // The ellipsis menu beside the switcher: create, edit, add widgets, delete.
                await page.locator('main .ember-basic-dropdown-trigger').nth(1).click();
                await page
                    .getByText(/add widget/i)
                    .first()
                    .click();
            },
            waitForAfterInteract: '.next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 1500,
            // The switch is saved server-side (is_default). Reset it, or console-dashboard in
            // the next theme pass shows the custom dashboard instead of the system one.
            cleanup: async (page) => {
                await page.evaluate(async () => {
                    const session = JSON.parse(localStorage.getItem('ember_simple_auth-session') || '{}');
                    await fetch('/int/v1/dashboards/reset-default', {
                        method: 'POST',
                        headers: { Accept: 'application/json', Authorization: `Bearer ${session?.authenticated?.token}` },
                    });
                });
            },
        },

        // ── Header trays ───────────────────────────────────────────────────────────────
        {
            id: 'notification-tray',
            file: 'console-notification-tray',
            url: '/',
            waitFor: '.notification-tray',
            interact: async (page) => {
                await page.locator('.notification-tray .local-selector-tray-trigger').first().click();
            },
            waitForAfterInteract: '.notification-tray-panel',
            extraSettleMs: 1000,
        },
        { id: 'notifications', file: 'console-notifications-list', url: '/notifications', waitFor: MAIN, extraSettleMs: 1200 },
        {
            id: 'chat-tray',
            file: 'console-chat-tray',
            url: '/',
            waitFor: '.chat-tray',
            interact: async (page) => {
                await page.locator('.chat-tray .chat-tray-panel-trigger').first().click();
            },
            waitForAfterInteract: '.chat-inbox-panel',
            extraSettleMs: 1200,
        },
        {
            id: 'chat-window',
            file: 'console-chat-window',
            url: '/',
            waitFor: '.chat-tray',
            interact: async (page) => {
                await page.locator('.chat-tray .chat-tray-panel-trigger').first().click();
                await page.locator('.chat-inbox-panel').getByText('Dispatch Team').first().click();
            },
            extraSettleMs: 1500,
        },
        {
            id: 'locale-select',
            file: 'console-locale-select',
            url: '/',
            waitFor: '.locale-selector-tray',
            interact: async (page) => {
                await page.locator('.locale-selector-tray .local-selector-tray-trigger').first().click();
            },
            waitForAfterInteract: '.locale-selector-tray-content',
            extraSettleMs: 800,
        },

        // ── Navigation ─────────────────────────────────────────────────────────────────
        {
            id: 'navigation-extensions-menu',
            file: 'console-navigation-extensions-menu',
            url: '/',
            waitFor: '.snm-more-btn',
            interact: async (page) => {
                await page.locator('.snm-more-btn').first().click();
            },
            waitForAfterInteract: '.snm-dropdown',
            extraSettleMs: 800,
        },
        {
            id: 'navigation-customize',
            file: 'console-navigation-customize',
            url: '/',
            waitFor: '.snm-customise-btn',
            interact: async (page) => {
                await page.locator('.snm-customise-btn').first().click();
            },
            waitForAfterInteract: '.snm-customizer-panel',
            extraSettleMs: 800,
        },
        {
            id: 'navigation-sidebar',
            file: 'console-navigation-sidebar',
            url: '/fleet-ops',
            waitFor: '.sidebar-toggle-button',
            interact: async (page) => {
                await page.locator('.sidebar-toggle-button').first().click();
            },
            extraSettleMs: 1200,
        },

        // ── Account (the signed-in user) ───────────────────────────────────────────────
        { id: 'account', file: 'console-account', url: '/account', waitFor: MAIN, extraSettleMs: 1200 },
        { id: 'account-security', file: 'console-user-two-factor', url: '/account/auth', waitFor: MAIN, extraSettleMs: 1200 },
        { id: 'account-organizations', file: 'console-organizations-list', url: '/account/organizations', waitFor: MAIN, extraSettleMs: 1200 },

        // ── Organization settings ──────────────────────────────────────────────────────
        { id: 'organization-settings', file: 'console-organization-edit', url: '/settings', waitFor: MAIN, extraSettleMs: 1200 },
        { id: 'organization-two-factor', file: 'console-organization-two-factor', url: '/settings/two-fa', waitFor: MAIN, extraSettleMs: 1200 },
        { id: 'organization-notifications', file: 'console-organization-notifications', url: '/settings/notifications', waitFor: MAIN, extraSettleMs: 1200 },
    ],
};
