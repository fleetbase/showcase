// capture/manifests/developers.mjs
//
// The Developers console (dev-engine, mounted at /developers). DevelopersSeeder adds API keys,
// two webhooks with delivery attempts, API events and request logs — all without model events,
// so nothing is dispatched to the fixture URLs and every key and public id is derived from its
// seed id. Detail screens take their ids from the seeder's announcements (`fromSeed`).

const TABLE = '.next-table-wrapper';
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';
const PANEL_OR_MAIN = '.next-content-overlay.is-open .next-content-overlay-panel, main';
const DIALOG = '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay), .next-content-overlay.is-open .next-content-overlay-panel';

const clickNew = async (page) => {
    await page
        .getByRole('button', { name: /new|create/i })
        .first()
        .click();
};

export default {
    area: 'developers',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        {
            id: 'api-keys',
            file: 'developers-api-keys-list',
            url: '/developers/api-keys',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
            extraSettleMs: 800,
        },
        {
            id: 'api-key-create',
            file: 'developers-api-key-create',
            url: '/developers/api-keys',
            waitFor: TABLE,
            interact: clickNew,
            waitForAfterInteract: DIALOG,
            extraSettleMs: 1000,
        },
        {
            id: 'webhooks',
            file: 'developers-webhooks-list',
            url: '/developers/webhooks',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 2 },
            extraSettleMs: 800,
        },
        {
            id: 'webhook-create',
            file: 'developers-webhook-create',
            url: '/developers/webhooks',
            waitFor: TABLE,
            interact: clickNew,
            waitForAfterInteract: DIALOG,
            extraSettleMs: 1000,
        },
        {
            id: 'webhook-details',
            file: 'developers-webhook-details',
            fromSeed: true,
            url: ({ publicId }) => `/developers/webhooks/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },
        {
            id: 'events',
            file: 'developers-events-list',
            url: '/developers/events',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 6 },
            extraSettleMs: 800,
        },
        {
            id: 'event-details',
            file: 'developers-event-details',
            fromSeed: true,
            url: ({ publicId }) => `/developers/events/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },
        {
            id: 'request-logs',
            file: 'developers-request-logs-list',
            url: '/developers/logs',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 8 },
            extraSettleMs: 800,
        },
        {
            id: 'request-log-details',
            file: 'developers-request-log-details',
            fromSeed: true,
            url: ({ publicId }) => `/developers/logs/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },
        { id: 'websockets', file: 'developers-websockets', url: '/developers/sockets', waitFor: 'main', extraSettleMs: 1500 },
    ],
};
