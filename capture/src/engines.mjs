// capture/src/engines.mjs
//
// Engine-version gating for manifest entries.
//
// Showcase captures whatever release the published images carry, and an engine's screens can
// change shape between releases: Storefront v0.4.25 replaced most of its console (store and
// network contexts, new routes), and Fleet-Ops v0.6.72 added the public tracking page. A
// manifest therefore carries both sets, each entry declaring the engine versions it is for:
//
//     { id: 'network-overview', requires: { 'storefront-engine': '>=0.4.25' }, ... }
//     { id: 'products',         requires: { 'storefront-engine': '<0.4.25' }, ... }
//
// The versions come from the console's own /extensions.json (name, version, route per engine),
// which the published console serves at its origin. An engine missing from the list fails a
// `>=` requirement and passes a `<` one, so a release without the engine keeps the old set.

export async function fetchEngineVersions(baseUrl) {
    try {
        const response = await fetch(new URL('/extensions.json', baseUrl));
        if (!response.ok) return {};
        const list = await response.json();
        return Object.fromEntries((Array.isArray(list) ? list : []).filter((e) => e?.name && e?.version).map((e) => [e.name, String(e.version)]));
    } catch {
        return {};
    }
}

function parse(version) {
    return String(version)
        .replace(/^v/, '')
        .split(/[.+-]/)
        .slice(0, 3)
        .map((part) => Number.parseInt(part, 10) || 0);
}

export function compareVersions(a, b) {
    const [x, y] = [parse(a), parse(b)];
    for (let i = 0; i < 3; i++) {
        if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) < (y[i] ?? 0) ? -1 : 1;
    }
    return 0;
}

/** True when `version` (or undefined: engine absent) meets a spec like ">=0.4.25" or "<0.4.25". */
export function satisfies(version, spec) {
    const match = /^\s*(>=|<=|>|<|=)?\s*v?([\d.]+)\s*$/.exec(spec);
    if (!match) throw new Error(`unsupported engine version spec "${spec}"`);
    const [, op = '=', target] = match;
    if (version === undefined) return op === '<' || op === '<=';
    const c = compareVersions(version, target);
    return { '>=': c >= 0, '<=': c <= 0, '>': c > 0, '<': c < 0, '=': c === 0 }[op];
}

/** Why an entry does not apply to these engine versions, or null when it does. */
export function engineMismatch(entry, versions) {
    for (const [engine, spec] of Object.entries(entry.requires ?? {})) {
        const name = engine.startsWith('@') ? engine : `@fleetbase/${engine}`;
        if (!satisfies(versions[name], spec)) return `${name} ${versions[name] ?? 'not installed'} does not meet ${spec}`;
    }
    return null;
}
