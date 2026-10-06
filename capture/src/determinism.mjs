// screenshots/src/determinism.mjs
//
// Everything that makes two runs of the same UI produce the same pixels.
//
// The failure mode this guards against is not "the screenshot is wrong" — it is
// "the screenshot is byte-different but visually identical", which turns every CI
// run into a pull request full of noise on the public marketing site. The ERD
// workflow already learned this lesson the expensive way (see 49371ff): mermaid
// renders identical input to SVGs that differ in Bezier control points, so the job
// opened a fresh PR after every merge. Browsers are worse than mermaid.
//
// The layers here, in order of how much they buy:
//   1. no network variance   — every third-party request is cached or aborted
//   2. no time variance      — Date is pinned
//   3. no animation variance — transitions zeroed, capture gated on quiescence
//   4. no raster variance    — fixed color profile, LCD text and hinting off

import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(HERE, '..', 'fixtures');

// The seeders build their fixtures from Carbon::parse('2026-01-15 08:00:00', 'Asia/Singapore').
// Anything rendered relative to "now" (created/updated columns, "3 days ago" cells) is a
// function of this constant, so it is part of the output contract: changing it re-renders
// every screenshot that shows a date.
export const FROZEN_TIME = new Date('2026-01-20T08:00:00+08:00');

// Must match the seeders' timezone. A runner in UTC renders every Singapore timestamp
// eight hours earlier, which is a real visual diff for a reason that has nothing to do
// with the console.
export const TIMEZONE = 'Asia/Singapore';

// Keep in sync with screenshots/package.json. The parity Docker image is
// mcr.microsoft.com/playwright:v<VERSION>-noble; ubuntu-latest runners are noble.
export const PLAYWRIGHT_VERSION = '1.62.1';

export const LAUNCH_ARGS = [
    // sRGB everywhere. Without this Chromium picks up the display profile, and a runner
    // image change shifts every pixel by a hair — enough to trip a pixel comparison on
    // all 32 files at once.
    '--force-color-profile=srgb',
    // Subpixel antialiasing and font hinting are the two largest sources of "same text,
    // different bytes". Turning both off costs a little crispness and buys reproducibility.
    '--disable-lcd-text',
    '--font-render-hinting=none',
    // GPU rasterization is not deterministic across machines; software raster is.
    '--disable-gpu',
    '--disable-software-rasterizer',
    '--disable-partial-raster',
    '--disable-skia-runtime-opts',
    // Scrollbar gutters differ between the host and the container image.
    '--hide-scrollbars',
    '--mute-audio',
    // Same reason console/testem.js sets it: required to run Chromium inside a container.
    ...(process.env.CI ? ['--no-sandbox', '--disable-dev-shm-usage'] : []),
];

// 1440x900 at deviceScaleFactor 2 => 2880x1800, which is exactly the dimensions of the
// screenshots already published on fleetbase.io, and the same logical viewport
// console/testem.js uses in CI. Matching it means compare.mjs can diff against the
// existing files instead of declaring all 66 of them resized.
export const DEFAULT_VIEWPORT = { width: 1440, height: 900 };
export const DEVICE_SCALE_FACTOR = 2;

/**
 * Context options for a capture session.
 *
 * `colorScheme` is the primary theme lever and it works because of how the theme service
 * resolves: persisted user option -> initialTheme -> prefers-color-scheme -> 'dark'. A
 * freshly seeded demo user has never toggled the theme, so there is no persisted option
 * and prefers-color-scheme decides. ensureTheme() below verifies that rather than trusting
 * it, and repairs the case where an option does exist.
 */
export function contextOptions({ viewport = DEFAULT_VIEWPORT, theme = 'dark' } = {}) {
    return {
        viewport,
        deviceScaleFactor: DEVICE_SCALE_FACTOR,
        locale: 'en-US',
        timezoneId: TIMEZONE,
        colorScheme: theme === 'light' ? 'light' : 'dark',
        reducedMotion: 'reduce',
        forcedColors: 'none',
        // The console is served from a different origin than the API in the compose stack;
        // capture is a trusted local context, so do not let a CSP block the init scripts.
        bypassCSP: true,
        // The realtime screens dial wss://<host>:38000, which the proxy terminates with Caddy's
        // internal CA (docker/caddy/Caddyfile). Without this the handshake fails and
        // admin-socket-settings shows "Socket connection error!". Safe: the listener only fronts
        // a container on the private compose network.
        ignoreHTTPSErrors: true,
    };
}

const FREEZE_CSS = `
*, *::before, *::after {
    animation-duration: 0s !important;
    animation-delay: 0s !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0s !important;
    transition-delay: 0s !important;
    scroll-behavior: auto !important;
    caret-color: transparent !important;
}
/* Leaflet animates tile fade-in and zoom independently of the global transition rules. */
.leaflet-fade-anim .leaflet-tile,
.leaflet-zoom-anim .leaflet-zoom-animated {
    transition: none !important;
}
/* The tile fade is not a CSS transition at all: GridLayer._updateOpacity sets each tile's
   inline opacity to min(1, (Date.now() - tile.loaded) / 200) on every animation frame. With
   the clock frozen that is 0 forever, so every tile downloads, decodes, counts as loaded —
   and renders invisible. That was the blank basemap on every map screen. !important beats
   the inline style. */
.leaflet-tile-loaded { opacity: 1 !important; }
/* A blinking cursor in a focused search field is a one-pixel-column diff that recurs at
   random depending on where in the blink cycle the capture lands. */
input, textarea { caret-color: transparent !important; }
`;

/**
 * Install everything that has to be in place BEFORE the app's first script runs:
 * the session, the pre-user theme option, and the frozen clock.
 *
 * All of it goes through addInitScript so it survives the in-app reloads and redirects
 * (/login -> /onboard -> /) that the console performs during boot.
 */
export async function applyInitScripts(context, { token, theme, apiHost, userId }) {
    // ember-simple-auth's adaptive store reads this key on boot. The shape is the login
    // response body with the authenticator name injected — see the precedent test at
    // console/tests/unit/models/report-test.js:414. The token must be a genuine Sanctum
    // token: authenticator:fleetbase.restore() re-validates it via GET auth/session on
    // every boot and invalidates the session if the server says no.
    const session = JSON.stringify({
        authenticated: { authenticator: 'authenticator:fleetbase', token, type: 'user' },
    });

    // THE THEME. Playwright's `colorScheme` is NOT sufficient, and this is not obvious.
    //
    // The theme service resolves in this order: persisted user option -> initialTheme ->
    // prefers-color-scheme -> 'dark'. But the server also serves a brand default
    // (GET int/v1/settings/branding -> brand.default_theme, which ships as "dark"), and the
    // console applies it — so on a stock install `prefers-color-scheme: light` is simply
    // ignored and every capture comes out dark. Verified: emulating light and dark both
    // produced body[data-theme="dark"].
    //
    // The persisted option is the one lever that wins. Keys are `<user uuid>:theme`, so the
    // uuid has to be known before boot, which is why bin/capture.mjs fetches it from
    // auth/session up front. `anon:theme` covers the window before the user model resolves.
    const options = { 'anon:theme': theme };
    if (userId) options[`${userId}:theme`] = theme;
    const userOptions = JSON.stringify(options);

    await context.addInitScript(
        ({ session, userOptions, styles }) => {
            window.localStorage.setItem('ember_simple_auth-session', session);
            window.localStorage.setItem('@fleetbase/storage:user-options', userOptions);

            // Both are caches with their own TTLs. A stale entry from an earlier run is a
            // silent source of "why did this screenshot not change".
            window.localStorage.removeItem('fleetbase_runtime_config');
            window.localStorage.removeItem('fleetbase_runtime_config_version');
            window.localStorage.removeItem('@fleetbase/storage:local-cache');

            // Register the fallbacks BEFORE the first attempt. An init script runs before the
            // document has a <head> — or even a documentElement — so the first attempt can
            // throw, and when the listener was registered after it nothing ever retried: the
            // captured DOM of every screen so far had no freeze stylesheet at all, which is
            // why the tile-opacity override "did not work".
            const inject = () => {
                try {
                    if (document.getElementById('flb-screenshot-freeze')) return;
                    const root = document.head || document.documentElement;
                    if (!root) return;
                    const el = document.createElement('style');
                    el.id = 'flb-screenshot-freeze';
                    el.textContent = styles;
                    root.appendChild(el);
                } catch {
                    // retried on the next lifecycle event
                }
            };
            document.addEventListener('readystatechange', inject);
            document.addEventListener('DOMContentLoaded', inject);
            inject();
        },
        { session, userOptions, styles: FREEZE_CSS }
    );

    // setSystemTime, deliberately, rather than setFixedTime or install()+pauseAt().
    //
    // install() replaces the timer functions and stops them advancing until told to, which
    // stalls Ember's runloop and ember-concurrency tasks — the app never finishes booting.
    //
    // setFixedTime looked right and was subtly fatal: it pins Date.now() while leaving
    // setTimeout running, but Ember's runloop (Backburner) schedules `later`/`next` as
    // `Date.now() + wait` and fires a timer only once `Date.now()` has passed that — so with
    // Date pinned, nothing scheduled through `next()` or `later()` ever runs. The order
    // details Overlay opens in a `next()`; Leaflet fades tiles in by `Date.now() - loaded`.
    // Both silently never happened.
    //
    // setSystemTime starts the clock at a fixed instant and lets it flow from there, so
    // relative-time rendering ("3 months ago") is stable to the day and the runloop works.
    for (const page of context.pages()) await page.clock.setSystemTime(FROZEN_TIME);
    context.on('page', (page) => page.clock.setSystemTime(FROZEN_TIME).catch(() => {}));

    return { apiHost };
}

// Hosts the console legitimately talks to. Everything else is aborted rather than allowed
// through: a request that succeeds today and 503s tomorrow is exactly how non-determinism
// gets back in, and an abort shows up immediately as a broken image instead of silently
// changing a run three weeks later.
// In the showcase stack baseUrl and apiHost are the SAME origin — the published console
// resolves its API host from window.location and drops the port, so one proxy serves both
// roles on :80. The two arguments stay separate so a split stack (a local `ember serve`
// against a separate API) still works.
function hostnameOf(url) {
    try {
        return new URL(url).hostname;
    } catch {
        return '';
    }
}

function isLocalOrigin(url, { baseUrl, apiHost }) {
    try {
        const { origin } = new URL(url);
        return origin === new URL(baseUrl).origin || origin === new URL(apiHost).origin;
    } catch {
        return false;
    }
}

// Path prefixes the API owns. The proxy is what actually routes; this is how we find out
// when it routed something wrongly. See installMisrouteGuard below.
const API_PATHS = /^\/(int|v1|storefront\/(int|v1)|ledger\/(int|v1|public)|customer-portal\/int|ai\/int|~registry|sanctum|health)(\/|$)/;

/**
 * Fail the run when an API request comes back as HTML.
 *
 * This is the guard for the nastiest failure mode in the whole pipeline. Now that the console
 * and the API share an origin, a path the proxy does not explicitly claim falls through to the
 * SPA — which answers 200 text/html for anything. The app then fails to parse JSON and renders
 * an empty table, and an empty table screenshots perfectly. Nothing is red, nothing errors, and
 * a picture of a broken screen ships to the marketing site.
 *
 * It is not hypothetical: /storefront and /ledger are simultaneously console engine mounts and
 * API prefixes, so the naive routing rule is already wrong. scripts/assert-routes.sh catches a
 * missing prefix at provision time; this catches it at capture time, per request, which also
 * covers prefixes that only appear under a particular page.
 */
export function installMisrouteGuard(page, { onMisroute }) {
    page.on('response', (response) => {
        try {
            const { pathname } = new URL(response.url());
            if (!API_PATHS.test(pathname)) return;

            const type = response.headers()['content-type'] ?? '';
            if (type.includes('text/html')) {
                onMisroute(`${pathname} returned text/html — the proxy is routing this API path to the console. ` + 'Add it to the @api matcher in docker/caddy/Caddyfile.');
            }
        } catch {
            /* not a URL we can parse; nothing to assert */
        }
    });
}

// The DEFAULT_*_IMAGE values in console/config/environment.js are compile-time constants
// baked into the bundle, and they are NOT in the runtime-config allowlist (which carries
// only API_HOST, API_NAMESPACE, SOCKETCLUSTER_*, OSRM_HOST and EXTENSIONS). So they cannot
// be pointed at a local file by configuration — they have to be intercepted.
const AVATAR_PATTERN = /flb-assets|s3[.-][a-z0-9-]*\.amazonaws\.com/i;

const CONTENT_TYPES = { '.png': 'image/png', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg' };

async function avatarFor(url) {
    // Match on the basename so a bucket or region rename — flb-assets is reachable under both
    // s3-ap-southeast-1.amazonaws.com and s3.ap-southeast-1.amazonaws.com today — does not
    // silently break the map.
    const name = decodeURIComponent(new URL(url).pathname.split('/').pop() || '');

    for (const candidate of [name, 'fallback-placeholder-1.png']) {
        try {
            const body = await readFile(path.join(FIXTURES, 'avatars', candidate));
            const contentType = CONTENT_TYPES[path.extname(candidate).toLowerCase()] ?? 'application/octet-stream';
            // `fallback` is reported by installRoutes. A silent fallback is how the Fleetbase
            // logo vanished from every screenshot: fleetbase-icon.png was not in fixtures,
            // the generic placeholder went out in its place, and nothing said so.
            return { body, contentType, fallback: candidate !== name };
        } catch {
            /* try the next candidate */
        }
    }

    return null;
}

/**
 * Route every request the page makes into one of four buckets: local (allow), known
 * remote asset (serve from fixtures), tile/routing (serve from the disk cache), or
 * unknown third party (abort loudly).
 */
// Google Maps loads its API, tiles, fonts and marker imagery from this family of hosts. Only
// allowed in the Google pass, and never cached: Google's terms forbid storing tiles, which is
// also why those captures are not byte-stable and are kept out of the compare gate.
const GOOGLE_MAPS_HOSTS = /(^|\.)(googleapis\.com|gstatic\.com|ggpht\.com|google\.com)$/i;

// The console looks up the viewer's location from the browser (ember-core's lookupUserIp:
// json.geoiplookup.io, then ipapi.co). Aborting both — as every unknown origin is — leaves
// it on fallback data, and since v0.7.68 current-user.js reports that with an orange
// "Unable to detect your location" toast across the top of the page: it was in 56 of 106
// screens of the v0.7.68 capture. Answer both instead, with one fixed location that matches
// the fixtures (Singapore). The IP is from 203.0.113.0/24, reserved for documentation.
// Each body is in its own provider's shape, because lookupUserIp normalises each one
// differently; the CORS header is required because the request is cross-origin.
const GEOIP_FIXTURES = {
    'json.geoiplookup.io': {
        ip: '203.0.113.10',
        city: 'Singapore',
        region: 'Central Singapore',
        country_code: 'SG',
        country_name: 'Singapore',
        continent_code: 'AS',
        continent_name: 'Asia',
        latitude: 1.2903,
        longitude: 103.8519,
        postal_code: '048616',
        timezone_name: 'Asia/Singapore',
        currency_code: 'SGD',
        currency_name: 'Singapore Dollar',
        language_code: 'en',
        language_name: 'English',
        isp: 'Fleetbase Showcase',
        org: 'Fleetbase Showcase',
        asn: 'AS64496',
        connection_type: 'corporate',
    },
    'ipapi.co': {
        ip: '203.0.113.10',
        city: 'Singapore',
        region: 'Central Singapore',
        country_code: 'SG',
        country_name: 'Singapore',
        continent_code: 'AS',
        latitude: 1.2903,
        longitude: 103.8519,
        postal: '048616',
        timezone: 'Asia/Singapore',
        currency: 'SGD',
        currency_name: 'Dollar',
        languages: 'en-SG,ms-SG,ta-SG,zh-SG',
        org: 'Fleetbase Showcase',
        asn: 'AS64496',
    },
};

// The browser clock is pinned to FROZEN_TIME, but the server's is not. Ledger's report and
// dashboard endpoints default their period to the server's "now" whenever the page sends no
// dates — and several widgets and report pages send none — so without this the AR aging would
// put every fixture invoice in the 90+ bucket, and the books would read differently each month.
// Fill in only the parameters the request left out, at the fixture date, so the server computes
// exactly what the browser believes today is. Parameter names are Ledger's ReportController's.
const REPORT_DATES = {
    as_of_date: '2026-01-20',
    start_date: '2025-09-01',
    end_date: '2026-01-20',
    date_from: '2025-09-01',
    date_to: '2026-01-20',
};
const PINNED_REPORT_PATHS = /\/ledger\/int\/v1\/reports\//;

function pinReportDates(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }
    if (!PINNED_REPORT_PATHS.test(parsed.pathname)) return null;
    let changed = false;
    for (const [key, value] of Object.entries(REPORT_DATES)) {
        if (!parsed.searchParams.has(key)) {
            parsed.searchParams.set(key, value);
            changed = true;
        }
    }
    return changed ? parsed.toString() : null;
}

/** The /storage/... path of an APP_URL (http://localhost) upload URL, else null. */
export function appStoragePath(url) {
    try {
        const parsed = new URL(url);
        if (parsed.hostname !== 'localhost' || !parsed.pathname.startsWith('/storage/')) return null;
        return parsed.pathname + parsed.search;
    } catch {
        return null;
    }
}

export async function installRoutes(page, { baseUrl, apiHost, tileCache, onBlocked, mapProvider = 'leaflet' }) {
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = request.url();

        if (url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
        if (isLocalOrigin(url, { baseUrl, apiHost })) {
            // Opening a chat message saves a read receipt, which turns the header's unread
            // badge from 2 to 1 for every screen captured after it — so the badge depended on
            // entry order and on whether the chat entry succeeded. Acknowledge the receipt
            // without persisting it.
            if (request.method() === 'POST' && /\/int\/v1\/chat-receipts\b/.test(url)) {
                let body = {};
                try {
                    body = JSON.parse(request.postData() || '{}');
                } catch {
                    /* echo an empty receipt */
                }
                const receipt = { ...(body.chatReceipt ?? body.chat_receipt ?? {}), id: 'showcase-receipt', uuid: 'showcase-receipt' };
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ chatReceipt: receipt }) });
            }
            // The parcel rate editor loads /engines-dist/images/boxes/<size>.png, which the
            // published console did not serve (broken images in the first capture). Serve
            // Fleet-Ops' own box images, but only when the console's request actually fails.
            const box = /\/engines-dist\/images\/boxes\/([a-z-]+\.png)$/.exec(new URL(url).pathname);
            if (box) {
                const response = await route.fetch().catch(() => null);
                if (response && response.ok()) return route.fulfill({ response });
                onBlocked?.(url, 'console returned no parcel box image; served capture/fixtures/boxes');
                const body = await readFile(path.join(FIXTURES, 'boxes', box[1])).catch(() => null);
                if (body) return route.fulfill({ status: 200, contentType: 'image/png', body });
            }
            const pinned = pinReportDates(url);
            return pinned ? route.continue({ url: pinned }) : route.continue();
        }
        if (mapProvider === 'google' && GOOGLE_MAPS_HOSTS.test(hostnameOf(url))) return route.continue();

        // Uploaded files (product photos, extension icons) come back as APP_URL/storage/...,
        // and APP_URL is http://localhost — the origin a developer's browser uses. Inside CI the
        // capture browser reaches the same proxy as http://proxy, so localhost is a foreign
        // origin there. Fetch the same path from the stack instead.
        const storagePath = appStoragePath(url);
        if (storagePath && baseUrl) {
            const response = await route.fetch({ url: new URL(storagePath, baseUrl).toString() });
            return route.fulfill({ response });
        }

        const geoip = GEOIP_FIXTURES[hostnameOf(url)];
        if (geoip) {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                headers: { 'access-control-allow-origin': '*' },
                body: JSON.stringify(geoip),
            });
        }

        if (AVATAR_PATTERN.test(url)) {
            const asset = await avatarFor(url);
            if (asset) {
                if (asset.fallback) onBlocked?.(url, 'served the generic placeholder — add this file to fixtures/avatars');
                return route.fulfill({ status: 200, contentType: asset.contentType, body: asset.body });
            }
            onBlocked?.(url, 'no avatar fixture');
            return route.abort();
        }

        if (tileCache?.handles(url)) {
            const hit = await tileCache.get(url);
            if (hit) return route.fulfill({ status: 200, contentType: hit.contentType, body: hit.body });
            onBlocked?.(url, 'tile cache miss in offline mode');
            return route.abort();
        }

        onBlocked?.(url, 'unpinned third-party origin');
        return route.abort();
    });
}

/**
 * Verify the app actually landed on the theme we asked for, and repair it if not.
 *
 * body[data-theme] is the Tailwind hook (console/tailwind.config.js sets
 * darkMode: ['class','[data-theme="dark"]']), so it is both the thing to assert on and
 * the thing that has to be right. The repair path exists because a user who HAS a
 * persisted theme option beats prefers-color-scheme, and we cannot know that user's uuid
 * until the app has loaded them.
 */
export async function ensureTheme(page, theme) {
    await page.waitForFunction(() => Boolean(document.body?.dataset?.theme), null, { timeout: 30_000 });
    const actual = await page.evaluate(() => document.body.dataset.theme);
    if (actual === theme) return { theme: actual, repaired: false };

    // Write the option for whichever user id the bucket has actually adopted, then reload
    // so the theme service re-resolves from persisted state rather than from a DOM poke
    // (a poke leaves the service's own bookkeeping disagreeing with the DOM).
    const repaired = await page.evaluate((wanted) => {
        const KEY = '@fleetbase/storage:user-options';
        let bucket = {};
        try {
            bucket = JSON.parse(window.localStorage.getItem(KEY)) || {};
        } catch {
            bucket = {};
        }
        const ids = new Set(['anon']);
        for (const key of Object.keys(bucket)) {
            const id = key.split(':')[0];
            if (id) ids.add(id);
        }
        for (const id of ids) bucket[`${id}:theme`] = wanted;
        window.localStorage.setItem(KEY, JSON.stringify(bucket));
        return [...ids];
    }, theme);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction((wanted) => document.body?.dataset?.theme === wanted, theme, { timeout: 30_000 });
    return { theme, repaired: true, ids: repaired };
}

/**
 * Block until the app has finished booting.
 *
 * #boot-loader is the canonical signal: console/app/utils/remove-boot-loader.js removes it,
 * and every route that renders a real screen calls that (routes/console.js:39,
 * routes/virtual.js:27, routes/application.js:89, routes/auth/login.js:12). Waiting on it
 * covers both the authenticated and the logged-out path.
 *
 * .overloader is the session service's transient full-screen veil, injected during
 * handleAuthentication() and removed on a 600-1800ms timer. Capturing through it produces
 * a screenshot of a spinner that looks, in a diff, exactly like a real UI change.
 */
/**
 * Belt and braces for the init-script injection above: after the app has booted, make sure
 * the freeze stylesheet really is in the document, and add it if not. Cheap, idempotent,
 * and the difference between "determinism" and "determinism we assumed".
 */
export async function ensureFreezeCss(page) {
    const present = await page.evaluate(() => Boolean(document.getElementById('flb-screenshot-freeze')));
    if (present) return false;
    await page.addStyleTag({ content: `/* flb-screenshot-freeze (late) */\n${FREEZE_CSS}` });
    await page.evaluate(() => {
        const el = [...document.querySelectorAll('style')].find((s) => s.textContent.startsWith('/* flb-screenshot-freeze (late) */'));
        if (el) el.id = 'flb-screenshot-freeze';
    });
    return true;
}

export async function waitForBoot(page, { timeout = 60_000 } = {}) {
    await page.waitForFunction(() => !document.getElementById('boot-loader'), null, { timeout });
    await page.waitForFunction(() => !document.querySelector('.overloader'), null, { timeout });
    await page.waitForFunction(() => Boolean(document.body?.dataset?.theme), null, { timeout });
}

/**
 * Block until nothing on the page is still moving.
 *
 * Ordering matters: the declared selector, then the entry's own interaction, THEN the row
 * assertion (the interaction is often what produces the rows), then the asynchronous tails —
 * fonts, images and map tiles — which only start once the content referencing them exists.
 */
export async function waitForQuiescence(page, entry, { timeout = 45_000, mapProvider = 'leaflet' } = {}) {
    // In the Google pass an entry may name a different element to wait for — the live map's
    // root is .google-live-map-container, not .leaflet-container.
    const waitFor = mapProvider === 'google' && entry.map?.google?.waitFor ? entry.map.google.waitFor : entry.waitFor;
    if (waitFor) {
        await page.waitForSelector(waitFor, { state: 'visible', timeout });
    }

    // Interact BEFORE asserting rows. The interaction is usually what produces the content the
    // assertion is about — the orders index opens on the map view with its table hidden and
    // unpopulated, so checking first reported "the fixture data is missing" about seven rows
    // that were one click away from existing.
    if (typeof entry.interact === 'function') {
        await entry.interact(page);
        if (entry.waitForAfterInteract) {
            await page.waitForSelector(entry.waitForAfterInteract, { state: 'visible', timeout });
        }
    }

    if (entry.expectRows) {
        const { selector, min } = entry.expectRows;
        // An empty table screenshots as a perfectly successful run. This is the check that
        // turns "the seeder did not populate this resource" into a failure instead of a
        // published screenshot of an empty state.
        await page
            .waitForFunction(({ selector, min }) => document.querySelectorAll(selector).length >= min, { selector, min }, { timeout })
            .catch(() => {
                throw new Error(`[${entry.id}] expected at least ${min} rows matching "${selector}"; ` + 'the fixture data is missing or the selector is stale');
            });
    }

    await page.evaluate(() => document.fonts?.ready).catch(() => {});

    // Every <img> either finished or failed. `complete` is true for both, which is what we
    // want — a broken avatar should not hang the run, it should show up in the screenshot
    // and be caught by review.
    await page.waitForFunction(() => [...document.images].every((img) => img.complete), null, { timeout });

    // A warning or error toast on screen is a defect in the screenshot, never content: the
    // v0.7.68 capture shipped "Unable to detect your location" in 56 of 106 screens because
    // nothing looked. Fail the entry instead, so the problem shows up as a red run with the
    // toast's text, not as a published image. (ember-cli-notifications markup.)
    const toast = await page.evaluate(() => {
        const el = document.querySelector('.c-notification--warning, .c-notification--error');
        return el ? el.textContent.replace(/\s+/g, ' ').trim() : null;
    });
    if (toast) {
        throw new Error(`[${entry.id}] a warning/error toast is on screen: "${toast.slice(0, 160)}"`);
    }

    if (entry.map?.settleTiles) {
        if (mapProvider === 'google') await waitForGoogleImagery(page, { timeout });
        else await waitForTiles(page, { timeout });
    }

    if (entry.extraSettleMs) await page.waitForTimeout(entry.extraSettleMs);
}

/**
 * Google Maps has no per-tile "loaded" class. What it does have is a .gm-style root whose
 * <img> tiles report `complete`, and a tile count that stops growing once the viewport is
 * covered — so, like waitForTiles, require the count to hold steady across two samples.
 */
async function waitForGoogleImagery(page, { timeout = 45_000, sampleMs = 400 } = {}) {
    const hasMap = await page.evaluate(() => document.querySelectorAll('.gm-style').length > 0);
    if (!hasMap) return;

    const deadline = Date.now() + timeout;
    let previous = -1;

    while (Date.now() < deadline) {
        const { loaded, pending } = await page.evaluate(() => {
            const images = [...document.querySelectorAll('.gm-style img')];
            return {
                loaded: images.filter((img) => img.complete).length,
                pending: images.filter((img) => !img.complete).length,
            };
        });

        if (pending === 0 && loaded === previous && loaded > 0) break;
        previous = loaded;
        await page.waitForTimeout(sampleMs);
    }
    if (previous <= 0) throw new Error('Google Maps imagery did not settle');

    // A key that is not authorised for this origin still renders a map — watermarked "For
    // development purposes only" behind a "This page can't load Google Maps correctly"
    // dialog. That is a key-configuration failure (HTTP-referrer restriction missing
    // http://proxy/* and http://localhost/*, or billing / the Maps JavaScript API not
    // enabled), and it must fail the capture rather than ship as a screenshot.
    const keyRejected = await page.evaluate(() => Boolean(document.querySelector('.gm-err-container')) || /can't load Google Maps correctly/i.test(document.body?.innerText ?? ''));
    if (keyRejected) {
        throw new Error(
            'Google Maps rejected the API key for this origin ("This page can\'t load Google Maps correctly"). ' +
                'Allow the referrers http://proxy/* and http://localhost/* on the key, and make sure the Maps ' +
                'JavaScript API is enabled with billing. See README → Google Maps variants.'
        );
    }
}

/**
 * Leaflet adds .leaflet-tile-loaded to each tile as it decodes. Waiting for "no unloaded
 * tiles" alone is not enough: panning and lazy tile creation mean the set can grow after it
 * momentarily looks complete. Requiring the count to hold steady across two samples is the
 * cheap fix.
 */
async function waitForTiles(page, { timeout = 45_000, sampleMs = 250 } = {}) {
    // A page that renders no map has nothing to settle. Without this the loop waits the full
    // timeout for a tile count that can never rise above zero and then fails with "map tiles
    // did not settle" — which reads like a network problem and is actually a manifest entry
    // claiming a map the page does not have. The Fleet-Ops scheduler is exactly that case on
    // v0.7.57.
    const hasMap = await page.evaluate(() => document.querySelectorAll('.leaflet-container').length > 0);
    if (!hasMap) return;

    const deadline = Date.now() + timeout;
    let previous = -1;

    while (Date.now() < deadline) {
        const { loaded, pending } = await page.evaluate(() => ({
            loaded: document.querySelectorAll('.leaflet-tile-loaded').length,
            pending: document.querySelectorAll('.leaflet-tile:not(.leaflet-tile-loaded)').length,
        }));

        if (pending === 0 && loaded === previous && loaded > 0) return;
        previous = loaded;
        await page.waitForTimeout(sampleMs);
    }

    throw new Error('map tiles did not settle');
}
