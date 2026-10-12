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

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { chromium } from 'playwright';
import { assertOnboarded, fetchSessionUserId, login } from '../src/auth.mjs';
import { captureAll } from '../src/capture.mjs';
import { LAUNCH_ARGS, PLAYWRIGHT_VERSION } from '../src/determinism.mjs';
import { engineMismatch, fetchEngineVersions } from '../src/engines.mjs';
import { assertFixturesAgree, resolveAll } from '../src/resolve.mjs';
import { TileCache } from '../src/tiles.mjs';

const { values } = parseArgs({
    options: {
        manifest: { type: 'string', default: 'manifests/fleet-ops.mjs' },
        out: { type: 'string', default: './out' },
        only: { type: 'string' },
        'offline-tiles': { type: 'boolean', default: false },
        'cache-dir': { type: 'string', default: './.cache' },
        // 'google' captures only the entries that declare `map.google`, with a -google suffix,
        // against a stack whose live map has been switched to Google Maps (scripts/capture.sh
        // does the switching). Google's terms forbid caching tiles, so this pass hits the
        // network live and its output is review material, not a byte-stable baseline.
        'map-provider': { type: 'string', default: 'leaflet' },
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

    const mapProvider = values['map-provider'];
    if (!['leaflet', 'google'].includes(mapProvider)) {
        console.error(`::error::--map-provider must be leaflet or google, got "${mapProvider}"`);
        process.exit(1);
    }

    let entries = manifest.entries;
    if (mapProvider === 'google') {
        entries = entries.filter((entry) => entry.map?.google);
        if (entries.length === 0) {
            console.log(`No entries in ${values.manifest} declare map.google; nothing to capture for Google Maps.`);
            return;
        }
    }
    if (values.only) {
        const wanted = new Set(values.only.split(',').map((id) => id.trim()));
        entries = entries.filter((entry) => wanted.has(entry.id));
        if (entries.length === 0) {
            console.error(`::error::--only "${values.only}" matched no entries in ${values.manifest}`);
            process.exit(1);
        }
    }

    // Entries declare the engine versions they are for (see src/engines.mjs); keep the ones this
    // release's console serves. An --only id that is gated out is reported, not silently dropped.
    const engineVersions = await fetchEngineVersions(baseUrl);
    const gated = entries.map((entry) => [entry, engineMismatch(entry, engineVersions)]);
    for (const [entry, reason] of gated) {
        if (reason) console.log(`  skipped ${entry.id}: ${reason}`);
    }
    entries = gated.filter(([, reason]) => !reason).map(([entry]) => entry);
    if (entries.length === 0) {
        console.log(`No entries in ${values.manifest} apply to this release's engines; nothing to capture.`);
        return;
    }

    console.log(`Capturing ${entries.length} entr${entries.length === 1 ? 'y' : 'ies'} x ${manifest.themes.length} theme(s)${mapProvider === 'google' ? ' [Google Maps]' : ''}`);
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

    const { ids, failures: resolveFailures } = await resolveAll({ apiHost, namespace, token }, entries);
    if (Object.keys(ids).length > 0) {
        log(`resolved ${Object.keys(ids).length} detail record(s): ${JSON.stringify(ids)}`);
    }
    for (const [id, message] of Object.entries(resolveFailures)) {
        log(`FAILED ${id}: ${message}`);
    }
    entries = entries.filter((entry) => !(entry.id in resolveFailures));

    // The seeders echo SEEDED_FIXTURES so two independent paths have to agree on which record a
    // detail page is showing. Optional — absent locally, present in CI.
    let seeded = {};
    if (process.env.SEEDED_FIXTURES) {
        try {
            seeded = JSON.parse(process.env.SEEDED_FIXTURES);
            assertFixturesAgree(ids, seeded);
            log('resolved ids agree with SEEDED_FIXTURES');
        } catch (error) {
            console.error(`::error::${error.message}`);
            process.exit(1);
        }
    }

    // `fromSeed: true` takes the id straight from what the seeder announced, for records the
    // internal API cannot look up by a stable field (webhook endpoints have no index route
    // and are addressed by uuid). Missing from the seed output means the seeder did not run —
    // the entry is reported as failed rather than navigated to with an undefined id.
    for (const entry of entries) {
        if (!entry.fromSeed) continue;
        if (seeded[entry.id]) {
            ids[entry.id] = seeded[entry.id];
        } else {
            resolveFailures[entry.id] = `fromSeed: no "${entry.id}" in SEEDED_FIXTURES`;
            log(`FAILED ${entry.id}: ${resolveFailures[entry.id]}`);
        }
    }
    entries = entries.filter((entry) => !(entry.id in resolveFailures));

    const tileCache = new TileCache({
        dir: path.resolve(values['cache-dir'], 'tiles'),
        offline: values['offline-tiles'],
    });

    const outDir = path.resolve(values.out);
    await mkdir(outDir, { recursive: true });

    const browser = await chromium.launch({ args: LAUNCH_ARGS });
    let captured;
    let failures = [];
    try {
        ({ captured, failures } = await captureAll({
            browser,
            manifest: { ...manifest, entries },
            ids,
            baseUrl,
            apiHost,
            token,
            userId,
            tileCache,
            outDir,
            mapProvider,
            log,
        }));
    } finally {
        await browser.close();
        // On failure this is the only place the tile statistics are reported, and a blank
        // basemap in the one screenshot that did come out is exactly when they are needed.
        log(tileCache.summary());
    }

    // The Google pass appends to the Leaflet pass's captures.json rather than replacing it, so
    // compare.mjs sees one list per area.
    let previous = [];
    if (mapProvider === 'google') {
        try {
            previous = JSON.parse(await readFile(path.join(outDir, 'captures.json'), 'utf8')).captured ?? [];
        } catch {
            previous = [];
        }
    }
    await writeFile(path.join(outDir, 'captures.json'), `${JSON.stringify({ area: manifest.area, defaultTheme: manifest.defaultTheme, captured: [...previous, ...captured] }, null, 2)}\n`);
    await writeFile(path.join(outDir, 'resolved-ids.json'), `${JSON.stringify(ids, null, 2)}\n`);

    const failedIds = [...new Set([...Object.keys(resolveFailures), ...failures.map((f) => f.id)])];

    // The workflow reads this to say in the pull request which screens are missing from it.
    await writeFile(path.join(outDir, 'failures.json'), `${JSON.stringify([...Object.entries(resolveFailures).map(([id, message]) => ({ id, message })), ...failures], null, 2)}\n`);

    console.log(
        `\n${failedIds.length ? '✖' : '✔'} wrote ${captured.length} file(s) to ${outDir}${failedIds.length ? `; ${failedIds.length} entr${failedIds.length === 1 ? 'y' : 'ies'} failed` : ''}`
    );
    if (failedIds.length > 0) {
        console.error(`::error::${failedIds.length} capture entr${failedIds.length === 1 ? 'y' : 'ies'} failed: ${failedIds.join(', ')}`);
        process.exit(1);
    }
}

main().catch((error) => {
    console.error(`::error::capture failed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
});
