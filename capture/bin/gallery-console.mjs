#!/usr/bin/env node
// screenshots/bin/gallery-console.mjs
//
// A review page for a console capture: every screen a run captured, grouped by area, dark and
// light side by side, click to enlarge. The console counterpart of gallery-mobile.mjs, built
// from the Console Screenshots workflow's artifact (out/<area>/*.webp plus failures.json).
// Open index.html straight from the unzipped artifact; it needs no server.
//
//   node bin/gallery-console.mjs --in ./out --out ./out/console-gallery --run 123 --release v0.7.74
//
// A screen shows up when either theme was captured; a missing twin is marked as a gap.
// Entries that failed to capture at all are listed at the top (failures.json). Images are
// scaled to 1400px wide WebP, enough for the enlarged view.

import { existsSync } from 'node:fs';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import sharp from 'sharp';

const { values } = parseArgs({
    options: {
        in: { type: 'string', default: './out' },
        out: { type: 'string', default: './out/console-gallery' },
        run: { type: 'string', default: '' },
        release: { type: 'string', default: '' },
    },
});

// Display order and names; an area not listed here still shows, after these, by its key.
const AREAS = [
    ['fleet-ops', 'Fleet-Ops'],
    ['storefront', 'Storefront'],
    ['ledger', 'Ledger'],
    ['iam', 'IAM'],
    ['developers', 'Developers'],
    ['general', 'General'],
    ['extensions', 'Extensions'],
    ['admin', 'Admin'],
];

const here = path.dirname(fileURLToPath(import.meta.url));
const inDir = path.resolve(values.in);
const outDir = path.resolve(values.out);

// fleet-ops-live-orders-panel-google → "Live orders panel (Google Maps)"
function label(area, name) {
    const google = name.endsWith('-google');
    const words = (google ? name.slice(0, -'-google'.length) : name).replace(new RegExp(`^${area}-`), '').replace(/-/g, ' ');
    return `${words.charAt(0).toUpperCase()}${words.slice(1)}${google ? ' (Google Maps)' : ''}`;
}

async function failures(dir) {
    try {
        return JSON.parse(await readFile(path.join(dir, 'failures.json'), 'utf8'));
    } catch {
        return [];
    }
}

async function main() {
    const present = (await readdir(inDir, { withFileTypes: true }))
        .filter((entry) => entry.isDirectory() && existsSync(path.join(inDir, entry.name, 'captures.json')))
        .map((entry) => entry.name);
    const known = AREAS.map(([key]) => key);
    const areas = [...AREAS.filter(([key]) => present.includes(key)), ...present.filter((key) => !known.includes(key)).sort().map((key) => [key, key])];

    let total = 0;
    let captured = 0;
    const failed = [];
    const sections = [];

    for (const [area, title] of areas) {
        const dir = path.join(inDir, area);
        const files = (await readdir(dir)).filter((file) => file.endsWith('.webp'));
        const names = [...new Set(files.map((file) => file.replace(/\.webp$/, '').replace(/-light$/, '')))].sort();
        await mkdir(path.join(outDir, 'shots', area), { recursive: true });

        let areaCaptured = 0;
        const rows = [];
        for (const name of names) {
            const cells = [];
            for (const [theme, suffix] of [['dark', ''], ['light', '-light']]) {
                total += 1;
                const file = `${name}${suffix}.webp`;
                if (!files.includes(file)) {
                    cells.push({ theme, src: null, file });
                    continue;
                }
                const target = `shots/${area}/${file}`;
                await sharp(path.join(dir, file)).resize({ width: 1400, withoutEnlargement: true }).webp({ quality: 80 }).toFile(path.join(outDir, target));
                captured += 1;
                areaCaptured += 1;
                cells.push({ theme, src: target, file });
            }
            rows.push({ name, label: label(area, name), cells });
        }
        for (const failure of await failures(dir)) failed.push(`${title} · ${failure.id} — ${String(failure.message ?? '').slice(0, 200)}`);
        sections.push({ key: area, title, rows, captured: areaCaptured, total: names.length * 2 });
    }

    const data = { run: values.run, release: values.release, total, captured, failures: failed, sections };
    const template = await readFile(path.join(here, '..', 'gallery', 'console.html'), 'utf8');
    // The shared look: the app gallery's stylesheet, with the console's own layout on top.
    const style = (await readFile(path.join(here, '..', 'gallery', 'template.html'), 'utf8')).match(/<style>[\s\S]*?<\/style>/)[0];
    const body = template.replace('<!--STYLE-->', style).replace('/*DATA*/null', JSON.stringify(data).replace(/</g, '\\u003c'));
    const page = `<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n</head>\n<body>\n${body}\n</body>\n</html>\n`;
    await writeFile(path.join(outDir, 'index.html'), page);

    const summary = `**Console screenshots:** ${captured} of ${total} captured across ${sections.length} areas${failed.length ? `; ${failed.length} entries failed` : ''}. Download the \`console-gallery\` artifact and open \`index.html\` to review them.`;
    if (process.env.GITHUB_STEP_SUMMARY) await writeFile(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`, { flag: 'a' });
    console.log(`${captured}/${total} screenshots in ${path.relative(process.cwd(), outDir) || '.'}/index.html`);
}

main().catch((error) => {
    console.error(`::error::gallery-console failed: ${error.message}`);
    process.exit(1);
});
