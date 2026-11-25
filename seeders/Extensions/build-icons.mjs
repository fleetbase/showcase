// seeders/Extensions/build-icons.mjs
//
// Draws one icon per extension in catalog.json, into media/icons/: its `glyph` (a Font Awesome 6 Free solid
// icon, CC BY 4.0, the set the console ships) in white on a diagonal gradient of its `colors`.
// Generated rather than drawn so the set stays consistent, and SVG so it is sharp at every size
// the console uses (28 to 104 px).
//
// Font Awesome is not a dependency of this repo. Install it anywhere and pass the folder:
//
//     npm install --prefix /tmp/fa @fortawesome/free-solid-svg-icons@6
//     node seeders/Extensions/build-icons.mjs /tmp/fa/node_modules/@fortawesome/free-solid-svg-icons
//
// To use an extension's real logo instead, replace media/icons/<slug>.svg by hand; the build only writes
// the files it is asked to (pass slugs after the folder), or all of them with none given.

import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const [fontAwesome, ...only] = process.argv.slice(2);
if (!fontAwesome) {
    console.error('usage: node build-icons.mjs <path to @fortawesome/free-solid-svg-icons> [slug ...]');
    process.exit(1);
}

const icons = createRequire(import.meta.url)(path.resolve(fontAwesome));
const catalog = JSON.parse(readFileSync(path.join(here, 'catalog.json'), 'utf8'));
const exportName = (glyph) => 'fa' + glyph.replace(/(^|-)([a-z0-9])/g, (_, __, letter) => letter.toUpperCase());

const SIZE = 256;
const GLYPH = 132; // the glyph's longer side

let written = 0;
for (const extension of catalog.extensions) {
    if (only.length && !only.includes(extension.slug)) continue;

    const definition = icons[exportName(extension.glyph)];
    if (!definition) {
        console.error(`${extension.slug}: Font Awesome has no solid icon named "${extension.glyph}"`);
        process.exit(1);
    }

    const [width, height, , , data] = definition.icon;
    const scale = GLYPH / Math.max(width, height);
    const x = (SIZE - width * scale) / 2;
    const y = (SIZE - height * scale) / 2;
    const [from, to] = extension.colors;
    const paths = (Array.isArray(data) ? data : [data]).map((d) => `<path fill="#fff" d="${d}"/>`).join('');

    const svg =
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${SIZE} ${SIZE}" width="${SIZE}" height="${SIZE}">` +
        `<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${from}"/><stop offset="1" stop-color="${to}"/></linearGradient></defs>` +
        `<rect width="${SIZE}" height="${SIZE}" fill="url(#g)"/>` +
        `<g transform="translate(${x.toFixed(2)} ${y.toFixed(2)}) scale(${scale.toFixed(5)})">${paths}</g>` +
        `</svg>\n`;

    writeFileSync(path.join(here, 'media/icons', `${extension.slug}.svg`), svg);
    written++;
}

console.log(`wrote ${written} icon(s)`);
