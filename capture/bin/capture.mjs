#!/usr/bin/env node
// screenshots/bin/capture.mjs
//
// Capture every entry in a manifest, in every theme, into an output directory.
//
//   BASE_URL=http://localhost:4200 API_HOST=http://localhost:8000 \
//   DEMO_IDENTITY=demo@fleetbase.io DEMO_PASSWORD='...' \
//     node bin/capture.mjs --manifest manifests/fleet-ops.mjs --out ./out
//
// Produces out/<name>.webp plus two sidecars that bin/compare.mjs reads:
//   out/captures.json     per-file DOM hash, byte size and source URL
//   out/resolved-ids.json which public_id each detail entry resolved to

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { chromium } from 'playwright';
import { assertOnboarded, fetchSessionUserId, login } from '../src/auth.mjs';
import { captureAll } from '../src/capture.mjs';
import { LAUNCH_ARGS, PLAYWRIGHT_VERSION } from '../src/determinism.mjs';
import { assertFixturesAgree, resolveAll } from '../src/resolve.mjs';
import { TileCache } from '../src/tiles.mjs';

const { values } = parseArgs({
    options: {
        manifest: { type: 'string', default: 'manifests/fleet-ops.mjs' },
        out: { type: 'string', default: './out' },
        only: { type: 'string' },
        'offline-tiles': { type: 'boolean', default: false },
        'cache-dir': { type: 'string', default: './.cache' },
    },
});

const log = (message) => console.log(`  ${message}`);

function required(name) {
    const value = process.env[name];
    if (!value) {
        console.error(`::error::${name} is not set. See the repo README.`);
        process.exit(1);
    }
    return value;
}

async function main() {
    const baseUrl = (process.env.BASE_URL ?? 'http://localhost:4200').replace(/\/$/, '');
    const apiHost = (process.env.API_HOST ?? 'http://localhost:8000').replace(/\/$/, '');
    const namespace = process.env.API_NAMESPACE ?? 'int/v1';
    // Credentials are only needed when this script has to log in for itself. The normal
    // path is scripts/capture.sh, which authenticates first, freezes the server-side
    // timestamps that a login writes, and passes the token in — see the SHOWCASE_TOKEN comment
    // below. Requiring an identity we are not going to use would make that path impossible.
    const hasToken = Boolean(process.env.SHOWCASE_TOKEN);
    const identity = hasToken ? (process.env.SHOWCASE_IDENTITY ?? null) : required('SHOWCASE_IDENTITY');
    const password = hasToken ? null : required('SHOWCASE_PASSWORD');

    // A version skew between the npm package and the parity Docker image means two different
    // Chromium builds, which means two different rasterizers, which means every file reads as
    // changed for no reason anyone will be able to explain later.
    const imageTag = process.env.PLAYWRIGHT_IMAGE_TAG;
    if (imageTag && !imageTag.includes(PLAYWRIGHT_VERSION)) {
        console.error(
            `::error::PLAYWRIGHT_IMAGE_TAG (${imageTag}) does not match the pinned playwright ` + `${PLAYWRIGHT_VERSION}. Captures from mismatched Chromium builds are not comparable.`
        );
        process.exit(1);
    }

    const manifestPath = path.resolve(values.manifest);
    const { default: manifest } = await import(manifestPath);

    let entries = manifest.entries;
    if (values.only) {
        const wanted = new Set(values.only.split(',').map((id) => id.trim()));
        entries = entries.filter((entry) => wanted.has(entry.id));
        if (entries.length === 0) {
            console.error(`::error::--only "${values.only}" matched no entries in ${values.manifest}`);
            process.exit(1);
        }
    }

    console.log(`Capturing ${entries.length} entr${entries.length === 1 ? 'y' : 'ies'} x ${manifest.themes.length} theme(s)`);
    console.log(`  console: ${baseUrl}`);
    console.log(`  api:     ${apiHost}/${namespace}`);

    // Both of these fail fast and for a legible reason, which matters more than it sounds:
    // an un-onboarded install answers every route with the same onboarding screen, so without
    // this check the run would "succeed" and publish 36 identical images.
    await assertOnboarded({ apiHost, namespace });

    // SHOWCASE_TOKEN lets the caller authenticate BEFORE the capture, which is what
    // scripts/capture.sh does — and it is not a convenience.
    //
    // Logging in writes `last_login` on the user row, and that column is rendered on the IAM
    // users screen. A login performed inside the capture therefore stamps wall-clock time into
    // a screenshot, and the file differs on every single run for a reason that has nothing to
    // do with the UI. Measured: with an in-capture login, 6 of 8 files were byte-identical
    // across two runs and the two users screens were not.
    //
    // So the order has to be: log in once, freeze the timestamps, then capture with the token
    // already in hand.
    const token = process.env.SHOWCASE_TOKEN || (await login({ apiHost, namespace, identity, password })).token;

    // Fetched before the browser starts because the theme is persisted per user under
    // `<uuid>:theme`, and that persisted option is the only thing that beats the server's
    // brand default. Discovering the uuid after boot instead is a race that silently
    // leaves every capture on the brand theme.
    const userId = await fetchSessionUserId({ apiHost, namespace, token });
    log('authenticated');

    const ids = await resolveAll({ apiHost, namespace, token }, entries);
    if (Object.keys(ids).length > 0) {
        log(`resolved ${Object.keys(ids).length} detail record(s): ${JSON.stringify(ids)}`);
    }

    // DemoSeeder echoes this so two independent paths have to agree on which record a detail
    // page is showing. Optional — absent locally, present in CI.
    if (process.env.SEEDED_FIXTURES) {
        try {
            assertFixturesAgree(ids, JSON.parse(process.env.SEEDED_FIXTURES));
            log('resolved ids agree with SEEDED_FIXTURES');
        } catch (error) {
            console.error(`::error::${error.message}`);
            process.exit(1);
        }
    }

    const tileCache = new TileCache({
        dir: path.resolve(values['cache-dir'], 'tiles'),
        offline: values['offline-tiles'],
    });

    const outDir = path.resolve(values.out);
    await mkdir(outDir, { recursive: true });

    const browser = await chromium.launch({ args: LAUNCH_ARGS });
    let captured;
    try {
        captured = await captureAll({
            browser,
            manifest: { ...manifest, entries },
            ids,
            baseUrl,
            apiHost,
            token,
            userId,
            tileCache,
            outDir,
            log,
        });
    } finally {
        await browser.close();
        // On failure this is the only place the tile statistics are reported, and a blank
        // basemap in the one screenshot that did come out is exactly when they are needed.
        log(tileCache.summary());
    }

    await writeFile(path.join(outDir, 'captures.json'), `${JSON.stringify({ area: manifest.area, defaultTheme: manifest.defaultTheme, captured }, null, 2)}\n`);
    await writeFile(path.join(outDir, 'resolved-ids.json'), `${JSON.stringify(ids, null, 2)}\n`);

    console.log(`\n✔ wrote ${captured.length} file(s) to ${outDir}`);
}

main().catch((error) => {
    console.error(`::error::capture failed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
});
