// capture/manifests/admin.mjs
//
// The system admin screens (/admin). They need the viewer to be a system admin, which the
// showcase owner deliberately is not — every other area should show what an ordinary operator
// sees. scripts/capture.sh therefore switches the owner to type `admin` for this area only and
// back afterwards (scripts/user-type.php), so these screens show the same user and organization
// as everything else.
//
// Almost no fixtures: these are configuration forms. The driver and mailer variants are the
// same form with a different value chosen in its <Select>, which is a native <select>.

const TABLE = '.next-table-wrapper';
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';
const FORM = 'main .container, main form, main';

const choose = (value) => async (page) => {
    await page.locator('main select').first().selectOption(value);
};

export default {
    area: 'admin',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },
    entries: [
        { id: 'branding', file: 'admin-branding-settings', url: '/admin/branding', waitFor: FORM, extraSettleMs: 1200 },
        {
            id: 'filesystem-public',
            file: 'admin-filesystem-settings-public',
            url: '/admin/config/filesystem',
            waitFor: 'main select',
            interact: choose('public'),
            extraSettleMs: 1000,
        },
        {
            id: 'filesystem-s3',
            file: 'admin-filesystem-settings-s3',
            url: '/admin/config/filesystem',
            waitFor: 'main select',
            interact: choose('s3'),
            extraSettleMs: 1000,
        },
        {
            id: 'mail-smtp',
            file: 'admin-mail-settings-smtp',
            url: '/admin/config/mail',
            waitFor: 'main select',
            interact: choose('smtp'),
            extraSettleMs: 1000,
        },
        {
            id: 'mail-ses',
            file: 'admin-mail-settings-ses',
            url: '/admin/config/mail',
            waitFor: 'main select',
            interact: choose('ses'),
            extraSettleMs: 1000,
        },
        { id: 'push-notifications', file: 'admin-push-notification-settings', url: '/admin/config/push-notifications', waitFor: FORM, extraSettleMs: 1200 },
        { id: 'queue', file: 'admin-queue-settings', url: '/admin/config/queue', waitFor: FORM, extraSettleMs: 1200 },
        { id: 'services', file: 'admin-services-settings', url: '/admin/config/services', waitFor: FORM, extraSettleMs: 1200 },
        { id: 'socket', file: 'admin-socket-settings', url: '/admin/config/socket', waitFor: FORM, extraSettleMs: 1200 },
        { id: 'two-factor', file: 'admin-two-factor-settings', url: '/admin/two-fa-settings', waitFor: FORM, extraSettleMs: 1200 },
        // platform/system-setup/oauth-sign-in has no screenshot; this is it.
        { id: 'oauth', file: 'admin-oauth-settings', url: '/admin/oauth-settings', waitFor: FORM, extraSettleMs: 1200 },
        {
            id: 'organizations',
            file: 'admin-organizations-list',
            url: '/admin/organizations',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 1 },
            extraSettleMs: 800,
        },
    ],
};
