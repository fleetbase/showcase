// capture/manifests/ledger.mjs
//
// Ledger (mounted at /ledger). LedgerSeeder posts four months of balanced books through
// Ledger's own LedgerService, so every report here is computed, not seeded: the balance sheet
// balances because each journal does. The report endpoints are pinned to the fixture date by
// capture/src/determinism.mjs (pinReportDates), so the server computes the same period the
// browser's frozen clock believes is current.

const TABLE = '.next-table-wrapper';
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';
const MAIN = 'main';
const PANEL_OR_MAIN = '.next-content-overlay.is-open .next-content-overlay-panel, main';
const DIALOG = '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay), .next-content-overlay.is-open .next-content-overlay-panel';

const list = (id, file, url, min) => ({ id, file, url, waitFor: TABLE, expectRows: { selector: TABLE_ROW, min }, extraSettleMs: 1000 });
const page = (id, file, url) => ({ id, file, url, waitFor: MAIN, extraSettleMs: 2000 });

export default {
    area: 'ledger',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        page('dashboard', 'ledger-dashboard', '/ledger'),

        // ── Reports ────────────────────────────────────────────────────────────────────
        page('report-ar-aging', 'ledger-report-ar-aging', '/ledger/reports/ar-aging'),
        page('report-balance-sheet', 'ledger-report-balance-sheet', '/ledger/reports/balance-sheet'),
        page('report-trial-balance', 'ledger-report-trial-balance', '/ledger/reports/trial-balance'),
        page('report-income-statement', 'ledger-report-income-statement', '/ledger/reports/income-statement'),
        page('report-cash-flow', 'ledger-report-cash-flow', '/ledger/reports/cash-flow'),
        page('report-wallet-summary', 'ledger-report-wallet-summary', '/ledger/reports/wallet-summary'),

        // ── Accounting ─────────────────────────────────────────────────────────────────
        list('accounts', 'ledger-accounts-list', '/ledger/accounting/accounts', 10),
        list('journal', 'ledger-journal-list', '/ledger/accounting/journal', 10),
        page('general-ledger', 'ledger-general-ledger', '/ledger/accounting/general-ledger'),

        // ── Billing ────────────────────────────────────────────────────────────────────
        list('invoices', 'ledger-invoices-list', '/ledger/billing/invoices', 10),
        {
            id: 'invoice-details',
            file: 'ledger-invoice-details',
            fromSeed: true,
            url: ({ publicId }) => `/ledger/billing/invoices/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },
        page('invoice-templates', 'ledger-invoice-templates-list', '/ledger/billing/invoice-templates'),

        // ── Payments ───────────────────────────────────────────────────────────────────
        list('transactions', 'ledger-transactions-list', '/ledger/payments/transactions', 5),
        page('wallets', 'ledger-wallets-list', '/ledger/payments/wallets'),
        {
            id: 'wallet-details',
            file: 'ledger-wallet-details',
            fromSeed: true,
            url: ({ publicId }) => `/ledger/payments/wallets/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },
        {
            id: 'wallet-add-funds',
            file: 'ledger-wallet-add-funds',
            fromSeed: true,
            url: ({ publicId }) => `/ledger/payments/wallets/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            interact: async (page) => {
                // Icon-only buttons in the panel header (label shows as a tooltip): Add Funds
                // is the primary one, Transfer the default one, Freeze the danger one.
                await page.locator('.next-content-overlay.is-open .resource-panel-header button.btn-primary').first().click();
            },
            waitForAfterInteract: DIALOG,
            extraSettleMs: 1000,
        },
        {
            id: 'wallet-transfer-funds',
            file: 'ledger-wallet-transfer-funds',
            fromSeed: true,
            url: ({ publicId }) => `/ledger/payments/wallets/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            interact: async (page) => {
                await page.locator('.next-content-overlay.is-open .resource-panel-header button.btn-default').first().click();
            },
            waitForAfterInteract: DIALOG,
            extraSettleMs: 1000,
        },
        page('gateways', 'ledger-gateways-list', '/ledger/payments/gateways'),
        {
            id: 'gateway-details',
            file: 'ledger-payment-gateway-details',
            fromSeed: true,
            url: ({ publicId }) => `/ledger/payments/gateways/${publicId}`,
            waitFor: PANEL_OR_MAIN,
            extraSettleMs: 1500,
        },

        // ── Settings ───────────────────────────────────────────────────────────────────
        page('settings-invoice', 'ledger-invoice-settings', '/ledger/settings/invoice'),
        page('settings-payment', 'ledger-payment-settings', '/ledger/settings/payment'),
    ],
};
