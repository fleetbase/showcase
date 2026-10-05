// screenshots/src/capture.mjs
//
// The capture loop: one browser context per theme, one page per entry.

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { applyInitScripts, contextOptions, ensureFreezeCss, ensureTheme, installRoutes, waitForBoot, waitForQuiescence } from './determinism.mjs';
import { domHash } from './domhash.mjs';
import { pngToWebp } from './encode.mjs';

/**
 * The output filename for an entry in a given theme.
 *
 * The manifest's defaultTheme owns the unsuffixed name. That is not cosmetic: the ~150
 * files already published on fleetbase.io are dark, and every reference to them in the
 * site's source is a literal string like '/images/screenshots/fleet-ops/x.webp'. Suffixing
 * dark would rename all of them and break every page that renders one.
 */
// Generous on purpose, and overridable: a natively-running browser needs a fraction of this,
// but the same code runs against an emulated amd64 Chromium on Apple silicon where boot is an
// order of magnitude slower.
const NAV_TIMEOUT = Number(process.env.CAPTURE_NAV_TIMEOUT_MS ?? 120_000);

// Generous: the slowest legitimate entry (first engine load on emulated amd64, a map with
// tiles, a 3s settle) finishes well inside two minutes.
const ENTRY_DEADLINE_MS = Number(process.env.CAPTURE_ENTRY_DEADLINE_MS ?? 240_000);
// Cap on each failure-recovery step (debug dump, closing the page).
const RECOVERY_MS = 15_000;

function withDeadline(promise, ms, message) {
    let timer;
    const deadline = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(message)), ms);
    });
    return Promise.race([promise, deadline]).finally(() => clearTimeout(timer));
}

export function fileNameFor(manifest, entry, theme, mapProvider = 'leaflet') {
    const base = mapProvider === 'google' ? `${entry.file}-google` : entry.file;
    return theme === manifest.defaultTheme ? `${base}.webp` : `${base}-${theme}.webp`;
}

function urlFor(entry, ids) {
    const url = typeof entry.url === 'function' ? entry.url({ ids, publicId: ids[entry.id] }) : entry.url;
    if (!url) throw new Error(`[${entry.id}] produced no URL`);
    return url;
}

/**
 * Screenshot until two consecutive captures agree.
 *
 * This is the cheapest possible insurance against a settle condition that is subtly wrong.
 * If the page is still moving, two captures 250ms apart differ, and we find out here —
 * where the error names the entry — instead of three steps later as an unexplained diff in
 * a pull request.
 */
async function captureStable(page, { attempts = 4, gapMs = 250, entryId }) {
    let previous = await page.screenshot({ type: 'png', animations: 'disabled' });

    for (let attempt = 1; attempt <= attempts; attempt += 1) {
        await page.waitForTimeout(gapMs);
        const next = await page.screenshot({ type: 'png', animations: 'disabled' });
        if (next.equals(previous)) return next;
        previous = next;
    }

    throw new Error(
        `[${entryId}] never stopped changing across ${attempts} captures ${gapMs}ms apart. ` +
            'Something on the page is still animating or polling; add a waitFor/extraSettleMs, ' +
            'or extend the freeze CSS in src/determinism.mjs.'
    );
}

/**
 * Capture every entry in every theme.
 *
 * @returns {Promise<Array<{id, file, theme, hash, bytes, url}>>}
 */
export async function captureAll({ browser, manifest, ids, baseUrl, apiHost, token, userId, tileCache, outDir, mapProvider = 'leaflet', log = () => {} }) {
    const captured = [];
    const failures = [];
    const blocked = new Set();

    await mkdir(outDir, { recursive: true });

    // The blocked-request list is reported in a finally: the run that fails on screen twelve
    // is the one whose first eleven screens had blank basemaps, and a blocked tile host is
    // the reason — so the report must survive the throw.
    try {
        for (const theme of manifest.themes) {
            const context = await browser.newContext(contextOptions({ viewport: manifest.viewport, theme }));
            await applyInitScripts(context, { token, theme, apiHost, userId });

            // One place to build a page, because a page is disposable: after an entry fails
            // or hangs, the next entry gets a fresh one rather than inheriting a wedged
            // navigation or a half-open dialog.
            const newPage = async () => {
                const page = await context.newPage();
                await installRoutes(page, {
                    baseUrl,
                    apiHost,
                    tileCache,
                    mapProvider,
                    onBlocked: (url, reason) => blocked.add(`${reason}: ${url}`),
                });
                // Accept every dialog. The one that matters is beforeunload: an edit form the
                // capture scrolled through counts as dirty, and Playwright's default — dismiss —
                // keeps the page, so the next goto never navigates and the run sits there until
                // something else times out (measured: 21 minutes on the vehicle edit form).
                page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
                return page;
            };

            // Boot at the console root and settle the theme. The session lives in localStorage
            // via an init script, but the app only picks it up on a real boot — a page handed
            // straight to a deep link renders the sign-in screen, which is exactly what the
            // entry after a failure captured once the loop started replacing pages.
            const bootPage = async (page) => {
                await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT });
                await waitForBoot(page);
                return ensureTheme(page, theme);
            };

            let page = await newPage();

            // Boot once against the console root before capturing anything. This pays for the
            // extension manager's lazy engine load, settles the session, and gives ensureTheme
            // a booted app to verify against — so a theme problem surfaces once here rather
            // than as sixteen wrong-theme screenshots.
            // NAV_TIMEOUT, not Playwright's 30s default. The first navigation is the slowest of
            // the run — it boots the Ember app, resolves the session and lazy-loads the engine —
            // and on an emulated amd64 browser it does not finish in 30s. Leaving it at the
            // default made the whole run fail on its very first goto while every later navigation
            // had 45s.
            const themeResult = await bootPage(page);
            log(`theme "${theme}" active${themeResult.repaired ? ' (repaired via persisted option)' : ''}`);

            for (const entry of manifest.entries) {
                const url = urlFor(entry, ids);
                const file = fileNameFor(manifest, entry, theme, mapProvider);

                // Everything an entry does — navigate, boot, settle, shoot — under one hard
                // deadline. The individual waits have their own timeouts, but a navigation
                // that never completes has none that fires, and this is the only guard
                // between "one screen is stuck" and "the job is stuck".
                // entry.cleanup(page) undoes server-side state an interaction left behind (a
                // dashboard switch persists, and would leak into every later screen). Runs
                // after the shot and after a failure, never fatally.
                const runCleanup = async () => {
                    if (typeof entry.cleanup !== 'function') return;
                    await withDeadline(entry.cleanup(page), RECOVERY_MS, 'cleanup hung').catch((e) => log(`  cleanup for ${entry.id} failed: ${e.message.split('\n')[0]}`));
                };

                const captureEntry = async () => {
                    if (entry.viewport) await page.setViewportSize(entry.viewport);
                    await page.goto(`${baseUrl}${url}`, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT });
                    await waitForBoot(page);
                    if (await ensureFreezeCss(page)) log(`  freeze stylesheet was missing on ${url}; injected late`);
                    await waitForQuiescence(page, entry, { mapProvider });

                    const png = await captureStable(page, { entryId: `${entry.id}/${theme}` });
                    const hash = await domHash(page);
                    const webp = await pngToWebp(png);
                    await writeFile(path.join(outDir, file), webp);

                    // CAPTURE_DEBUG=1 also saves the page's HTML next to each screenshot. This
                    // exists for iterating from CI alone: when a screen comes out wrong — a
                    // panel that did not open, a board with every card in one column — the DOM
                    // says why, and a developer without a local stack has no other way to see it.
                    if (process.env.CAPTURE_DEBUG) {
                        const debugDir = path.join(outDir, 'debug');
                        await mkdir(debugDir, { recursive: true });
                        await writeFile(path.join(debugDir, `${entry.id}-${theme}.html`), await page.content());
                    }
                    await runCleanup();
                    captured.push({ id: entry.id, file, theme, hash, bytes: webp.length, url, provider: mapProvider });
                    log(`captured ${file} (${(webp.length / 1024).toFixed(0)} KB) from ${url}`);
                    if (entry.viewport) await page.setViewportSize(manifest.viewport);
                };

                try {
                    await withDeadline(captureEntry(), ENTRY_DEADLINE_MS, `[${entry.id}] exceeded the ${ENTRY_DEADLINE_MS / 1000}s entry deadline`);
                } catch (error) {
                    // Keep going. A batch of twenty new entries with unverified selectors
                    // would otherwise take twenty CI runs to debug; recording the failure and
                    // moving on makes it one run. bin/capture.mjs still exits non-zero.
                    await runCleanup();
                    failures.push({ id: entry.id, theme, url, message: error.message.split('\n')[0] });
                    log(`FAILED ${entry.id} (${theme}): ${error.message.split('\n')[0]}`);

                    // The screen that failed is the one whose DOM matters most, and it is the
                    // one the success path never saves. Bounded: on a renderer stuck in a JS
                    // loop, page.content() and page.close() have no timeout of their own —
                    // storefront settings-locations turned a 240s deadline into 16 minutes.
                    if (process.env.CAPTURE_DEBUG) {
                        const debugDir = path.join(outDir, 'debug');
                        await mkdir(debugDir, { recursive: true });
                        const html = await withDeadline(page.content(), RECOVERY_MS, 'page.content() hung').catch(() => '');
                        await writeFile(path.join(debugDir, `${entry.id}-${theme}-FAILED.html`), html);
                    }

                    // Whatever state the page is in — mid-navigation, dialog up, Ember error
                    // substate — the next entry does not inherit it.
                    await withDeadline(page.close(), RECOVERY_MS, 'page.close() hung').catch(() => {});
                    page = await newPage();
                    await bootPage(page).catch(() => {});
                    continue;
                }
            }

            await context.close();
        }
    } finally {
        // Surfaced rather than thrown: a blocked request may be perfectly fine (an analytics
        // beacon), but if a screenshot looks wrong this list is the first place to look.
        if (blocked.size > 0) {
            log(`blocked ${blocked.size} third-party request(s):`);
            for (const entry of [...blocked].slice(0, 20)) log(`  - ${entry}`);
        }
    }

    if (failures.length > 0) {
        log(`${failures.length} entr${failures.length === 1 ? 'y' : 'ies'} failed:`);
        for (const failure of failures) log(`  - ${failure.id} (${failure.theme}) ${failure.url}: ${failure.message}`);
    }

    return { captured, failures };
}
