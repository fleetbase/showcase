#!/usr/bin/env node
// screenshots/bin/compare.mjs
//
// Decide which captured screenshots are worth publishing, and stage those into a checkout
// of fleetbase.io.
//
//   node bin/compare.mjs \
//     --new     ./out \
//     --current ../fleetbase.io/public/images/screenshots/fleet-ops \
//     --staged  ../fleetbase.io/public/images/screenshots/fleet-ops
//
// A file that survives the gate is copied; one that does not is left alone, so the working
// tree stays clean for it and git never sees a change. That is the same move erd.yml makes
// when it runs `git checkout -- erd.svg` after deciding the schema did not change.
//
// --dry-run reports without copying anything.

import { createHash } from 'node:crypto';
import { copyFile, mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { DEFAULT_CHANGED_RATIO, DEFAULT_PIXEL_THRESHOLD, comparePair, toMarkdownTable } from '../src/compare.mjs';
import { decide } from '../src/domhash.mjs';

const { values } = parseArgs({
    options: {
        new: { type: 'string', default: './out' },
        current: { type: 'string' },
        staged: { type: 'string' },
        report: { type: 'string', default: './out/report.json' },
        'hash-baseline': { type: 'string', default: './.cache/domhash/baseline.json' },
        'pixel-threshold': { type: 'string', default: String(DEFAULT_PIXEL_THRESHOLD) },
        'changed-ratio': { type: 'string', default: String(DEFAULT_CHANGED_RATIO) },
        'dry-run': { type: 'boolean', default: false },
        // Google Maps captures are fetched live (no tile cache — Google's terms) and are not
        // byte-stable between runs, so by default they are reported but never staged.
        'include-google': { type: 'boolean', default: false },
    },
});

async function readIfPresent(file) {
    try {
        return await readFile(file);
    } catch {
        return null;
    }
}

async function readJsonIfPresent(file, fallback) {
    const raw = await readIfPresent(file);
    if (!raw) return fallback;
    try {
        return JSON.parse(raw.toString());
    } catch {
        return fallback;
    }
}

async function main() {
    if (!values.current) {
        console.error('::error::--current is required (the published screenshots directory)');
        process.exit(1);
    }

    const newDir = path.resolve(values.new);
    const currentDir = path.resolve(values.current);
    const stagedDir = values.staged ? path.resolve(values.staged) : null;

    const captures = await readJsonIfPresent(path.join(newDir, 'captures.json'), null);
    if (!captures) {
        console.error(`::error::no captures.json in ${newDir}; run bin/capture.mjs first`);
        process.exit(1);
    }

    const baselinePath = path.resolve(values['hash-baseline']);
    const baseline = await readJsonIfPresent(baselinePath, {});

    const pixelThreshold = Number(values['pixel-threshold']);
    const changedRatio = Number(values['changed-ratio']);

    const results = [];
    // The baseline maps an image's CONTENT (sha256 of the .webp bytes) to the DOM hash of the
    // capture that produced it. Keyed that way, "render noise" can only be declared about the
    // file that is actually published: if the image on fleetbase.io is one this pipeline
    // captured, we know its DOM; if it is hand-made, or the capture that produced it never
    // merged, there is no entry and the decision falls back to pixels alone.
    //
    // It used to be keyed by entry id and recorded on every run, published or not — so a
    // second run before the pull request merged compared against a DOM the site never had,
    // and dropped 18 genuine updates from the v0.7.68 pull request as "render noise".
    const sha = (buffer) => createHash('sha256').update(buffer).digest('hex');
    const nextBaseline = {};

    for (const capture of captures.captured) {
        if (capture.provider === 'google' && !values['include-google']) {
            console.log(`review  ${capture.file.padEnd(46)} Google Maps capture; not staged without --include-google`);
            continue;
        }
        const nextBuffer = await readFile(path.join(newDir, capture.file));
        const currentBuffer = await readIfPresent(path.join(currentDir, capture.file));

        const comparison = await comparePair(nextBuffer, currentBuffer, { pixelThreshold, changedRatio });

        const publishedSha = currentBuffer ? sha(currentBuffer) : null;
        const hashBefore = publishedSha ? baseline[publishedSha] : undefined;
        const verdict = decide({
            status: comparison.status,
            pixelChanged: comparison.status === 'changed',
            hashBefore,
            hashAfter: capture.hash,
        });

        // Keep what the next run can use: the DOM behind the file published now, and the DOM
        // behind this capture — which becomes the published file if this run's pull request
        // merges. Entries for captures that were superseded before merging drop out here.
        if (publishedSha && hashBefore) nextBaseline[publishedSha] = hashBefore;
        if (capture.hash) nextBaseline[sha(nextBuffer)] = capture.hash;

        results.push({
            id: capture.id,
            file: capture.file,
            theme: capture.theme,
            url: capture.url,
            status: comparison.status,
            ratio: comparison.ratio,
            changedPixels: comparison.changedPixels,
            dimensions: comparison.dimensions,
            previousDimensions: comparison.previousDimensions,
            hashBefore: hashBefore ?? null,
            hashAfter: capture.hash ?? null,
            included: verdict.include,
            reason: verdict.reason,
        });

        if (comparison.diff) {
            const diffDir = path.join(newDir, 'diff');
            await mkdir(diffDir, { recursive: true });
            await writeFile(path.join(diffDir, capture.file.replace(/\.webp$/, '.png')), comparison.diff);
        }

        const label = verdict.include ? 'PUBLISH' : 'skip   ';
        const pct = Number.isFinite(comparison.ratio) ? `${(comparison.ratio * 100).toFixed(2)}%` : '—';
        console.log(`${label} ${capture.file.padEnd(46)} ${verdict.reason} (${pct})`);

        // Loud, because a silently dropped file is indistinguishable from a file that never
        // changed — and one of those two means the pipeline is broken.
        if (verdict.reason.startsWith('render noise')) {
            console.log(`::notice::${capture.file} differs in pixels but not in DOM structure; treated as render noise and not published.`);
        }

        if (verdict.include && stagedDir && !values['dry-run']) {
            await mkdir(stagedDir, { recursive: true });
            await copyFile(path.join(newDir, capture.file), path.join(stagedDir, capture.file));
        }
    }

    const included = results.filter((result) => result.included);

    // A capture the manifest no longer produces is left in place deliberately: the library
    // has ~150 files and this manifest covers 18 of them, so absence here means "not managed
    // by this pipeline", never "delete".
    const published = await readdir(currentDir).catch(() => []);
    const unmanaged = published.filter((file) => file.endsWith('.webp') && !captures.captured.some((capture) => capture.file === file));

    await mkdir(path.dirname(path.resolve(values.report)), { recursive: true });
    await writeFile(path.resolve(values.report), `${JSON.stringify({ area: captures.area, results, unmanagedCount: unmanaged.length }, null, 2)}\n`);

    if (!values['dry-run']) {
        await mkdir(path.dirname(baselinePath), { recursive: true });
        await writeFile(baselinePath, `${JSON.stringify(nextBaseline, null, 2)}\n`);
    }

    const table = toMarkdownTable(results);
    const summary = [
        `**${included.length}** of ${results.length} captured screenshots changed enough to publish.`,
        '',
        table,
        '',
        `<sub>${unmanaged.length} other file(s) in this directory are not managed by the capture manifest and were left untouched.</sub>`,
    ].join('\n');

    if (process.env.GITHUB_OUTPUT) {
        await writeFile(process.env.GITHUB_OUTPUT, `changed_count=${included.length}\n`, { flag: 'a' });
        // Multiline outputs need a heredoc with a delimiter that cannot appear in the body.
        const delimiter = `PRBODY_${Date.now().toString(36)}`;
        await writeFile(process.env.GITHUB_OUTPUT, `pr_body<<${delimiter}\n${summary}\n${delimiter}\n`, { flag: 'a' });
    }
    if (process.env.GITHUB_STEP_SUMMARY) {
        await writeFile(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`, { flag: 'a' });
    }

    console.log(`\n${included.length}/${results.length} file(s) staged${values['dry-run'] ? ' (dry run — nothing copied)' : ''}`);
}

main().catch((error) => {
    console.error(`::error::compare failed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
});
