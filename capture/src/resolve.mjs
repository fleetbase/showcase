// screenshots/src/resolve.mjs
//
// Turn a stable fixture handle into the public_id a detail-page URL needs.
//
// The seeders assign a fresh Str::uuid() to every record on every run, and public_id is
// generated from it, so detail URLs cannot be written into the manifest. What IS stable is
// the human-meaningful field the seeder sets deliberately: a driver's name, an order's
// internal_id. Look the record up by that, once, before the browser starts.

/**
 * @param {{apiHost: string, namespace?: string, token: string}} api
 * @param {{resource: string, query: string, match: {field: string, value: string}}} spec
 * @returns {Promise<string>} the record's public_id
 */
export async function resolvePublicId({ apiHost, namespace = 'int/v1', token }, spec) {
    const base = `${apiHost}/${namespace}/${spec.resource}`;
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

    // The internal index endpoints accept ?query= for fuzzy search, which keeps the response
    // small. Not every resource honours it, so fall back to paging and filtering locally
    // rather than concluding the record does not exist.
    let rows = await getRows(`${base}?query=${encodeURIComponent(spec.query)}&limit=50`, headers);
    if (rows.length === 0) rows = await getRows(`${base}?limit=200`, headers);

    // Case-insensitive: several models upper-case what they store (Place names come back
    // as RAFFLES QUAY), and a manifest should not have to know which.
    const { field, value } = spec.match;
    const wanted = String(value).trim().toLowerCase();
    const hits = rows.filter(
        (row) =>
            String(row?.[field] ?? '')
                .trim()
                .toLowerCase() === wanted
    );

    // Exactly one, or stop. A screenshot of the wrong driver is worse than no screenshot:
    // it is wrong in a way that survives review, because nothing about it looks broken.
    if (hits.length !== 1) {
        const sample = rows
            .slice(0, 10)
            .map((row) => `${row?.[field] ?? '?'}=${row?.public_id ?? '?'}`)
            .join(', ');
        throw new Error(
            `resolve(${spec.resource}): expected exactly 1 record with ${field}="${value}", got ${hits.length}. ` + `Checked ${rows.length} records. Candidates: ${sample || 'none'}`
        );
    }

    const publicId = hits[0]?.public_id;
    if (!publicId) {
        throw new Error(`resolve(${spec.resource}): matched a record with no public_id for ${field}="${value}"`);
    }

    return publicId;
}

async function getRows(url, headers) {
    const response = await fetch(url, { headers });
    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText} for ${url}`);
    }
    const body = await response.json();

    // Three envelopes, in order of specificity.
    //
    // The third is the one that actually matters and is easy to miss: Fleetbase's internal
    // index endpoints key the collection by RESOURCE NAME, not `data` — GET /int/v1/drivers
    // answers `{"drivers": [...]}`. Assuming `data` returns an empty array, which the caller
    // then reports as "expected exactly 1 record, got 0. Checked 0 records" — a message that
    // reads like missing fixtures when the fixtures are fine.
    if (Array.isArray(body)) return body;
    if (Array.isArray(body?.data)) return body.data;

    const collection = Object.values(body ?? {}).find((value) => Array.isArray(value));
    return collection ?? [];
}

/**
 * Resolve every entry in the manifest that declares a `resolve` block.
 *
 * Runs before the browser launches so a data problem costs a few seconds rather than a
 * whole capture pass, and returns a plain { [entryId]: publicId } map that url() callbacks
 * read from.
 */
export async function resolveAll(api, entries) {
    const ids = {};
    // One unresolvable record must not abort a fifty-entry run before the browser has even
    // started; the entry is dropped and reported, and the caller decides the exit code.
    const failures = {};

    for (const entry of entries) {
        if (!entry.resolve) continue;
        try {
            ids[entry.id] = await resolvePublicId(api, entry.resolve);
        } catch (error) {
            failures[entry.id] = error.message;
        }
    }

    return { ids, failures };
}

/**
 * Cross-check the resolver against what the seeder says it created.
 *
 * DemoSeeder echoes a SEEDED_FIXTURES={...} line for exactly this purpose. Two independent
 * paths agreeing is what distinguishes "resolved the right record" from "resolved a record".
 * A disagreement means the demo data and the manifest have drifted apart, which is precisely
 * when a silent wrong-record screenshot would otherwise ship.
 */
export function assertFixturesAgree(resolvedIds, seededFixtures) {
    if (!seededFixtures || Object.keys(seededFixtures).length === 0) return;

    const mismatches = [];
    for (const [id, publicId] of Object.entries(resolvedIds)) {
        const expected = seededFixtures[id];
        if (expected && expected !== publicId) {
            mismatches.push(`${id}: resolved ${publicId}, seeder reported ${expected}`);
        }
    }

    if (mismatches.length > 0) {
        throw new Error(`resolved ids disagree with SEEDED_FIXTURES:\n  ${mismatches.join('\n  ')}`);
    }
}
