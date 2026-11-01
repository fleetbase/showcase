#!/usr/bin/env node
// screenshots/bin/gallery-mobile.mjs
//
// A review page for a Storefront app capture: every expected screen in a row, iOS and Android
// in dark and light side by side, gaps marked, click to enlarge. Open index.html straight from
// the unzipped artifact; it needs no server.
//
//   node bin/gallery-mobile.mjs \
//     --ios ./out/raw/storefront-app-ios --android ./out/raw/storefront-app-android \
//     --out ./out/storefront-app-gallery --run 123 --app-ref v0.1.1 --release v0.7.69
//
// Images are scaled to 1100px wide WebP, enough for the enlarged view and small enough that a
// full run stays a few MB. failures.txt from each platform is listed at the top.

import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import sharp from 'sharp';
import { STOREFRONT_APP_EDITIONS, STOREFRONT_APP_PLATFORMS, STOREFRONT_APP_THEMES } from '../src/storefront-app-screens.mjs';

const { values } = parseArgs({
    options: {
        ios: { type: 'string' },
        android: { type: 'string' },
        out: { type: 'string', default: './out/storefront-app-gallery' },
        run: { type: 'string', default: '' },
        'app-ref': { type: 'string', default: '' },
        release: { type: 'string', default: '' },
    },
});

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(values.out);
const sources = { ios: values.ios ? path.resolve(values.ios) : null, android: values.android ? path.resolve(values.android) : null };

async function skipped(platform) {
    const dir = sources[platform];
    if (!dir) return {};
    try {
        const lines = (await readFile(path.join(dir, 'skipped.txt'), 'utf8')).split('\n').map((line) => line.trim()).filter(Boolean);
        return Object.fromEntries(lines.map((line) => [line.split(':')[0], line.slice(line.indexOf(':') + 1).trim()]));
    } catch {
        return {};
    }
}

async function failures(platform) {
    const dir = sources[platform];
    if (!dir) return [];
    try {
        return (await readFile(path.join(dir, 'failures.txt'), 'utf8')).split('\n').map((line) => line.trim()).filter(Boolean);
    } catch {
        return [];
    }
}

async function main() {
    await mkdir(path.join(outDir, 'shots'), { recursive: true });

    const skippedEditions = { ...(await skipped('ios')), ...(await skipped('android')) };
    let total = 0;
    let captured = 0;
    const perPlatform = { ios: 0, android: 0 };
    const sections = [];

    for (const edition of STOREFRONT_APP_EDITIONS) {
        const skipReason = skippedEditions[edition.key] ?? null;
        let editionCaptured = 0;
        const rows = [];
        for (const [name, label] of edition.screens) {
            const cells = [];
            for (const platform of STOREFRONT_APP_PLATFORMS) {
                for (const [theme, suffix] of STOREFRONT_APP_THEMES) {
                    if (!skipReason) total += 1;
                    const file = `${name}${suffix}.png`;
                    const source = sources[platform] ? path.join(sources[platform], file) : null;
                    if (!source || !existsSync(source)) {
                        cells.push({ platform, theme, src: null, file, skipped: !!skipReason });
                        continue;
                    }
                    const target = `shots/${platform}-${name}${suffix}.webp`;
                    await sharp(source).resize({ width: 1100, withoutEnlargement: true }).webp({ quality: 80 }).toFile(path.join(outDir, target));
                    captured += 1;
                    editionCaptured += 1;
                    perPlatform[platform] += 1;
                    cells.push({ platform, theme, src: target, file });
                }
            }
            rows.push({ name, label, cells });
        }
        sections.push({ key: edition.key, title: edition.title, note: skipReason ? `${edition.note} Skipped: ${skipReason}.` : edition.note, rows, captured: editionCaptured, total: edition.screens.length * 4, skipped: !!skipReason });
    }

    const data = {
        run: values.run,
        appRef: values['app-ref'],
        release: values.release,
        total,
        captured,
        perPlatform,
        failures: { ios: await failures('ios'), android: await failures('android') },
        sections,
    };

    const template = await readFile(path.join(here, '..', 'gallery', 'template.html'), 'utf8');
    const body = template.replace('/*DATA*/null', JSON.stringify(data).replace(/</g, '\\u003c'));
    const page = `<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n<title>Storefront app screenshots</title>\n</head>\n<body>\n${body}\n</body>\n</html>\n`;
    await writeFile(path.join(outDir, 'index.html'), page);

    const summary = `**Storefront app screenshots:** ${captured} of ${total} captured (iOS ${perPlatform.ios}, Android ${perPlatform.android}). Download the \`storefront-app-gallery\` artifact and open \`index.html\` to review them.`;
    if (process.env.GITHUB_STEP_SUMMARY) await writeFile(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`, { flag: 'a' });
    if (process.env.GITHUB_OUTPUT) await writeFile(process.env.GITHUB_OUTPUT, `captured=${captured}\ntotal=${total}\n`, { flag: 'a' });
    console.log(`${captured}/${total} screenshots in ${path.relative(process.cwd(), outDir) || '.'}/index.html`);
}

main().catch((error) => {
    console.error(`::error::gallery-mobile failed: ${error.message}`);
    process.exit(1);
});
