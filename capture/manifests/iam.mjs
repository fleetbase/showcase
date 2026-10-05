// capture/manifests/iam.mjs
//
// The IAM console screens. Filenames match what fleetbase.io already publishes under
// public/images/screenshots/iam/, so each entry updates a file the marketing pages reference
// rather than adding an orphan.
//
// Only four of the eleven published iam-*.webp files are reachable by URL alone. The other
// seven (iam-create-user, iam-create-group, iam-create-policy, iam-create-role,
// iam-invite-user, iam-attach-policies-to-user, iam-manage-group-members) are modal or
// drawer states and need an `interact` step; they are deliberately left out of this first
// pass rather than guessed at.
//
// Roles and policies need NO showcase fixtures: `php artisan fleetbase:create-permissions`
// runs during provisioning and creates 27 roles plus the full policy set. Only the users and
// groups screens depend on the IAM seeder.

const TABLE = '.next-table-wrapper';
const TABLE_ROW = '.next-table-wrapper tbody tr:not(.next-table-empty-state-row)';

export default {
    area: 'iam',
    defaultTheme: 'dark',
    themes: ['dark', 'light'],
    viewport: { width: 1440, height: 900 },

    entries: [
        {
            id: 'users-management',
            file: 'iam-users-list',
            url: '/iam/users',
            waitFor: TABLE,
            // Nine seeded users plus the organization owner.
            expectRows: { selector: TABLE_ROW, min: 9 },
        },
        {
            id: 'groups-management',
            file: 'iam-groups-list',
            url: '/iam/groups',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 3 },
        },
        {
            id: 'roles-management',
            file: 'iam-roles-list',
            url: '/iam/roles',
            waitFor: TABLE,
            // Platform-provided, not seeded — 27 of them at v0.7.57. The floor is deliberately
            // well below that so a release trimming the default set does not fail the run.
            expectRows: { selector: TABLE_ROW, min: 10 },
        },
        {
            id: 'policy-management',
            file: 'iam-policies-list',
            url: '/iam/policies',
            waitFor: TABLE,
            expectRows: { selector: TABLE_ROW, min: 5 },
        },
        // ── Create dialogs ────────────────────────────────────────────────────────────
        // Interaction only: each opens from a button in the list header.
        ...[
            ['user-create', 'iam-user-create', '/iam/users', /^\s*new\s*$/i],
            ['user-invite', 'iam-user-invite', '/iam/users', /invite/i],
            ['group-create', 'iam-group-create', '/iam/groups', /^\s*new\s*$/i],
            ['role-create', 'iam-role-create', '/iam/roles', /^\s*new\s*$/i],
            ['policy-create', 'iam-policy-create', '/iam/policies', /^\s*new\s*$/i],
        ].map(([id, file, url, button]) => ({
            id,
            file,
            url,
            waitFor: TABLE,
            interact: async (page) => {
                await page.getByRole('button', { name: button }).first().click();
            },
            waitForAfterInteract: '.modal-dialog, .modal-content, .ember-modal-dialog, [role="dialog"]:not(.next-content-overlay), .next-content-overlay.is-open .next-content-overlay-panel',
            extraSettleMs: 1000,
        })),
    ],
};
