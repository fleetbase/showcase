#!/usr/bin/env node
// screenshots/bin/encode-mobile.mjs
//
// Turn the Storefront app's simulator and emulator PNGs (scripts/mobile/capture.sh) into the
// published form, and describe them the way bin/compare.mjs expects a capture run to.
//
//   node bin/encode-mobile.mjs --new ./out/storefront-app/ios --out ./out/storefront-app-ios --area storefront-app/ios
//
// Lossless WebP, because that is what the existing app screenshots on fleetbase.io are: a
// phone screenshot is mostly flat UI and text, which lossless compresses well, and lossy
// chroma subsampling visibly softens small type at 3x.
//
// There is no DOM on a phone, so captures.json carries no hash and the compare gate decides
// on pixels alone.

import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import sharp from 'sharp';

export const MOBILE_WEBP_OPTIONS = { lossless: true, effort: 6 };

const { values } = parseArgs({
    options: {
        new: { type: 'string' },
        out: { type: 'string' },
        area: { type: 'string' },
    },
});

async function main() {
    if (!values.new || !values.out || !values.area) {
        console.error('::error::--new, --out and --area are required');
        process.exit(1);
    }

    const sourceDir = path.resolve(values.new);
    const outDir = path.resolve(values.out);
    await mkdir(outDir, { recursive: true });

    const pngs = (await readdir(sourceDir).catch(() => [])).filter((file) => file.endsWith('.png')).sort();
    const captured = [];

    for (const png of pngs) {
        const id = png.replace(/\.png$/, '');
        const file = `${id}.webp`;
        const webp = await sharp(await readFile(path.join(sourceDir, png)))
            .webp(MOBILE_WEBP_OPTIONS)
            .toBuffer();
        await writeFile(path.join(outDir, file), webp);
        captured.push({ id, file, theme: id.endsWith('-light') ? 'light' : 'dark', url: null, hash: null });
        console.log(`encoded ${file}`);
    }

    await writeFile(path.join(outDir, 'captures.json'), `${JSON.stringify({ area: values.area, captured }, null, 2)}\n`);
    console.log(`\n${captured.length} screenshot(s) encoded into ${path.relative(process.cwd(), outDir) || '.'}`);
}

main().catch((error) => {
    console.error(`::error::encode-mobile failed: ${error.message}`);
    process.exit(1);
});
