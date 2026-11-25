// docker/registry/server.mjs
//
// A stand-in for the Fleetbase Registry Service (github.com/fleetbase/registry), for the
// Extensions screens only.
//
// WHY THIS EXISTS
//
// From registry-bridge 0.3.0 (Fleetbase v0.9.0) the console's Extensions screens no longer read
// a catalogue from the instance's own database. The API asks the Registry Service for it, and
// for what this instance has installed and bought. A fresh stack pointed at the real
// registry.fleetbase.io would screenshot whatever is published that day, to an instance that is
// not registered, so every button would read "Not connected".
//
// The real service is not booted here because it publishes no image (it deploys to ECS from
// its own repository), and seeding it would mean writing its internal tables from this repo.
// What the screenshots need is only its HTTP answers, so this serves those from one file,
// seeders/Extensions/catalog.json, which ExtensionsSeeder reads too.
//
// WHAT IT ANSWERS
//
// Exactly the endpoints registry-bridge's RegistryClient calls, in the shapes of the service's
// own resources (ExtensionSummaryResource, ExtensionResource, ReleaseResource) as recorded in
// registry-bridge's server/tests/Fixtures/registry-service:
//
//   GET  /v1/catalog/extensions                 q, category, pricing, hosting, featured, sort, page, limit
//   GET  /v1/catalog/extensions/{slug|id}
//   GET  /v1/catalog/extensions/{slug|id}/releases
//   GET  /v1/catalog/categories
//   POST /v1/instances/register                 any code registers the one showcase instance
//   GET  /v1/instances/me                       the instance and its organization's licences
//   GET  /v1/instances/me/updates               what is installed, and what has a newer release
//   GET  /v1/instances/me/compatibility
//   POST /v1/instances/me/installs              accepted and forgotten
//   DELETE /v1/instances/me/installs/{id}       accepted and forgotten
//
// It keeps no state: an install reported by the console is acknowledged and not remembered, so
// a capture that clicks Get cannot change what the next run sees. Every id and date is derived
// from the catalogue file, never from the clock.
//
// No dependencies, so the container is `node server.mjs` on a stock Node image.

import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { createServer as createHttpServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const INSTANCE_TOKEN = 'flbi_showcase00000000000000000000000000000000';

/** Product names of the console modules an extension can add to (`compat.extensions`). */
const MODULE_NAMES = {
    '@fleetbase/fleetops-engine': 'Fleet-Ops',
    '@fleetbase/storefront-engine': 'Storefront',
    '@fleetbase/ledger-engine': 'Ledger',
};

const sha = (algorithm, text) => createHash(algorithm).update(text).digest('hex');

/** A service-style public id (`ext_3fa9c1d2e0`), the same on every run and in ExtensionsSeeder. */
export function publicId(prefix, key) {
    return `${prefix}_${sha('sha1', `showcase:${key}`).slice(0, 10)}`;
}

/** Laravel's JSON date format, `days` before the catalogue's own "now". */
function daysBefore(now, days) {
    return new Date(now.getTime() - days * 86_400_000).toISOString().replace(/\.\d{3}Z$/, '.000000Z');
}

function parseVersion(version) {
    return String(version)
        .replace(/^[^\d]*/, '')
        .split(/[.+-]/)
        .slice(0, 3)
        .map((part) => Number.parseInt(part, 10) || 0);
}

export function compareVersions(a, b) {
    const [x, y] = [parseVersion(a), parseVersion(b)];
    for (let i = 0; i < 3; i++) {
        if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) < (y[i] ?? 0) ? -1 : 1;
    }
    return 0;
}

/** Only the range form the catalogue uses (`>=1.2.3`); anything else is taken as compatible. */
function satisfiesRange(version, range) {
    const match = /^\s*>=\s*v?([\d.]+)\s*$/.exec(range ?? '');
    return match ? compareVersions(version, match[1]) >= 0 : true;
}

/**
 * Expand the compact catalogue file into the service's records.
 *
 * @param {object} data      seeders/Extensions/catalog.json
 * @param {object} [options]
 * @param {string} [options.mediaBase] URL prefix the browser loads icons and screenshots from
 * @param {(file: string) => boolean} [options.hasMedia] whether a media file exists (icons are optional)
 */
export function buildCatalog(data, { mediaBase = '/showcase-registry', hasMedia = () => true } = {}) {
    const now = new Date(data.now);
    const defaults = data.defaults ?? {};

    const publishers = Object.fromEntries(
        Object.entries(data.publishers).map(([key, publisher]) => [
            key,
            {
                id: publicId('org', `publisher:${key}`),
                slug: key,
                name: publisher.name,
                website: publisher.website ?? null,
                bio: null,
                avatar_url: null,
                is_verified: publisher.verified === true,
                created_at: daysBefore(now, 600),
            },
        ])
    );

    const categories = data.categories.map((category, index) => ({
        id: publicId('cat', `category:${category.slug}`),
        slug: category.slug,
        name: category.name,
        description: category.description ?? null,
        icon: category.icon ?? 'shapes',
        tags: [],
        sort: index,
    }));
    const categoryBySlug = Object.fromEntries(categories.map((category) => [category.slug, category]));

    const extensions = data.extensions.map((entry) => {
        const publisherKey = entry.publisher ?? 'fleetbase';
        const publisher = publishers[publisherKey];
        const category = categoryBySlug[entry.category];
        if (!publisher) throw new Error(`catalog: ${entry.slug} names an unknown publisher "${publisherKey}"`);
        if (!category) throw new Error(`catalog: ${entry.slug} names an unknown category "${entry.category}"`);

        const id = publicId('ext', `extension:${entry.slug}`);
        const packages = { npm: `@${publisherKey}/${entry.slug}-engine`, composer: `${publisherKey}/${entry.slug}-api` };
        const compat = { ...(entry.compat ?? defaults.compat ?? {}), ...(entry.adds_to ? { extensions: entry.adds_to } : {}) };
        const updatedAt = daysBefore(now, entry.updated ?? 0);
        const publishedAt = daysBefore(now, entry.published ?? entry.updated ?? 0);

        // Newest first. Each older release is a month before the one after it.
        const releases = [entry.version, ...(entry.versions ?? [])].map((version, index) => {
            const key = `release:${entry.slug}@${version}`;
            const published = index === 0 ? updatedAt : daysBefore(now, (entry.updated ?? 0) + index * 31);
            const digest = sha('sha256', `showcase:${key}`);
            return {
                id: publicId('rel', key),
                version,
                channel: 'stable',
                status: 'published',
                compat,
                size_bytes: 180_000 + (Number.parseInt(digest.slice(0, 5), 16) % 900_000),
                checksum: `sha256:${digest}`,
                notes: index === 0 ? (entry.notes ?? null) : null,
                // A prebuilt console engine, so the console can load it without a rebuild. No
                // `built_against`: the bridge would compare it with this console's own versions.
                dist: {
                    url: `/v1/instances/me/releases/${publicId('rel', key)}/dist`,
                    size: 90_000 + (Number.parseInt(digest.slice(5, 10), 16) % 400_000),
                    sha256: sha('sha256', `showcase:dist:${key}`),
                    integrity: null,
                    built_against: {},
                },
                published_at: published,
                yanked_at: null,
                created_at: published,
            };
        });

        const price = entry.price > 0 ? entry.price : 0;
        const iconFile = `icons/${entry.slug}.svg`;
        const featuredAt = entry.featured ? daysBefore(now, entry.featured) : null;

        const summary = {
            id,
            slug: entry.slug,
            name: entry.name,
            subtitle: entry.subtitle ?? null,
            fa_icon: entry.glyph ?? 'puzzle-piece',
            icon_url: hasMedia(iconFile) ? `${mediaBase}/${iconFile}` : null,
            tags: entry.tags ?? [],
            highlights: entry.highlights ?? [],
            setup_steps: entry.setup_steps ?? [],
            hosting: entry.hosting ?? defaults.hosting ?? 'both',
            status: 'published',
            visibility: 'public',
            publisher: { id: publisher.id, slug: publisher.slug, name: publisher.name, is_verified: publisher.is_verified },
            category: { id: category.id, slug: category.slug, name: category.name, icon: category.icon },
            latest_release: releases[0],
            latest_version: releases[0].version,
            compat,
            packages,
            price: { model: price > 0 ? 'one_time' : 'free', amount: price, currency: 'USD', on_sale: false, sale_amount: null, effective_amount: price },
            is_paid: price > 0,
            installs_count: entry.installs ?? 0,
            published_at: publishedAt,
            featured_at: featuredAt,
            is_featured: featuredAt !== null,
            updated_at: updatedAt,
            created_at: publishedAt,
        };

        const links = entry.links ?? {};
        const detail = {
            ...summary,
            description: entry.description ?? entry.subtitle ?? null,
            promotional_text: null,
            languages: entry.languages ?? defaults.languages ?? ['en'],
            website_url: links.website ?? publisher.website,
            repo_url: links.source ?? null,
            support_url: links.support ?? null,
            privacy_url: links.privacy ?? null,
            tos_url: links.terms ?? null,
            copyright: `${publisher.name}`,
            requires_extensions: Object.entries(entry.adds_to ?? {}).map(([name, range]) => ({ package: name, name: MODULE_NAMES[name] ?? name, range })),
            screenshots: (entry.screenshots ?? []).map((file, index) => ({
                id: publicId('file', `screenshot:${entry.slug}:${index}`),
                url: `${mediaBase}/screenshots/${file}`,
                name: file,
                mime: 'image/webp',
            })),
            versions: releases.map((release) => ({ id: release.id, version: release.version, channel: release.channel, published_at: release.published_at, dist: release.dist })),
            versions_count: releases.length,
            publisher,
        };

        return { entry, id, summary, detail, releases, featuredOrder: entry.featured ?? null };
    });

    for (const category of categories) {
        category.extensions_count = extensions.filter((extension) => extension.summary.category.id === category.id).length;
    }

    const organization = {
        id: publicId('org', `organization:${data.organization.slug}`),
        slug: data.organization.slug,
        name: data.organization.name,
        website: null,
        bio: null,
        avatar_url: null,
        is_personal: false,
        is_staff: false,
        is_verified: true,
        verified_at: daysBefore(now, 200),
        created_at: daysBefore(now, 240),
    };

    const reference = (extension) => ({ id: extension.id, slug: extension.summary.slug, name: extension.summary.name });

    // What the instance has: a release that may be older than the newest one.
    const installed = extensions
        .filter((extension) => extension.entry.installed)
        .map((extension) => {
            const version = extension.entry.installed.version ?? extension.releases[0].version;
            const release = extension.releases.find((candidate) => candidate.version === version);
            if (!release) throw new Error(`catalog: ${extension.summary.slug} is installed at ${version}, which is not one of its versions`);
            return { extension, release, updateAvailable: compareVersions(extension.releases[0].version, version) > 0 };
        });

    // A paid licence for what was bought, and the free licence an install creates.
    const licences = extensions
        .filter((extension) => extension.entry.purchased || (extension.entry.installed && !extension.summary.is_paid))
        .map((extension) => ({
            id: publicId('lic', `licence:${extension.summary.slug}`),
            extension: reference(extension),
            kind: extension.summary.is_paid ? 'paid' : 'free',
            status: 'active',
            max_activations: extension.summary.is_paid ? 5 : null,
            seats_used: extension.entry.installed ? 1 : 0,
            activated_here: Boolean(extension.entry.installed),
            expires_at: null,
            created_at: daysBefore(now, 30 + (extension.entry.updated ?? 0)),
        }));

    return { now, publishers, categories, extensions, organization, installed, licences, reference, instance: data.instance ?? {} };
}

const SORTS = {
    popular: (a, b) => b.summary.installs_count - a.summary.installs_count,
    newest: (a, b) => b.summary.published_at.localeCompare(a.summary.published_at),
    updated: (a, b) => b.summary.updated_at.localeCompare(a.summary.updated_at),
    name: (a, b) => a.summary.name.localeCompare(b.summary.name, 'en', { sensitivity: 'base' }),
    price: (a, b) => a.summary.price.amount - b.summary.price.amount,
    // Staff-featured first, in their featured order; the rest by popularity.
    featured: (a, b) => (a.featuredOrder ?? Infinity) - (b.featuredOrder ?? Infinity) || b.summary.installs_count - a.summary.installs_count,
};

/** One page of `GET /v1/catalog/extensions`. */
export function listExtensions(catalog, query = {}) {
    const text = String(query.q ?? '')
        .trim()
        .toLowerCase();
    let items = catalog.extensions.filter((extension) => {
        const s = extension.summary;
        if (text && ![s.name, s.subtitle, extension.detail.description, s.publisher.name, s.category.name, ...s.tags].some((field) => String(field ?? '').toLowerCase().includes(text))) return false;
        if (query.category && ![s.category.id, s.category.slug].includes(query.category)) return false;
        if (query.pricing === 'free' && s.is_paid) return false;
        if (query.pricing === 'paid' && !s.is_paid) return false;
        if (query.hosting === 'both' && s.hosting !== 'both') return false;
        if (['cloud', 'self_hosted'].includes(query.hosting) && ![query.hosting, 'both'].includes(s.hosting)) return false;
        if (['1', 'true'].includes(String(query.featured ?? '')) && !s.is_featured) return false;
        if (['1', 'true'].includes(String(query.compatible ?? '')) && query.console && !satisfiesRange(query.console, s.compat.console)) return false;
        return true;
    });

    // Popularity breaks every tie, and the slug breaks that one, so the order never depends on
    // the engine's sort stability.
    const order = SORTS[query.sort] ?? SORTS.popular;
    items = items.sort((a, b) => order(a, b) || SORTS.popular(a, b) || a.summary.slug.localeCompare(b.summary.slug));

    const perPage = Math.max(1, Math.min(Number.parseInt(query.limit, 10) || 24, 100));
    const page = Math.max(1, Number.parseInt(query.page, 10) || 1);
    const total = items.length;
    const slice = items.slice((page - 1) * perPage, page * perPage);

    return {
        data: slice.map((extension) => extension.summary),
        meta: {
            total,
            per_page: perPage,
            current_page: page,
            last_page: Math.max(1, Math.ceil(total / perPage)),
            from: slice.length ? (page - 1) * perPage + 1 : null,
            to: slice.length ? (page - 1) * perPage + slice.length : null,
        },
    };
}

function findExtension(catalog, key) {
    const wanted = decodeURIComponent(key);
    return catalog.extensions.find((extension) => extension.id === wanted || extension.summary.slug === wanted || Object.values(extension.summary.packages).includes(wanted)) ?? null;
}

function instanceRecord(catalog, registration = {}) {
    return {
        id: publicId('inst', 'instance'),
        name: registration.name ?? catalog.instance.name ?? 'Fleetbase Showcase',
        host: registration.host ?? 'http://localhost',
        kind: registration.kind ?? catalog.instance.kind ?? 'self_hosted',
        console_version: registration.console_version ?? null,
        api_version: registration.api_version ?? null,
        status: 'active',
        organization: catalog.organization,
        last_seen_at: daysBefore(catalog.now, 0),
        created_at: daysBefore(catalog.now, 120),
    };
}

/**
 * Answer one request: `{ status, body }`. Pure, so the tests call it without a socket.
 *
 * @param {object} catalog from buildCatalog()
 * @param {{method: string, path: string, query?: object, token?: string|null, body?: object}} request
 */
export function route(catalog, { method, path: pathname, query = {}, token = null, body = {} }) {
    const segments = pathname.split('/').filter(Boolean);
    const json = (status, payload) => ({ status, body: payload });
    const is = (verb, ...parts) => method === verb && segments.length === parts.length && parts.every((part, index) => part === null || part === segments[index]);

    if (is('GET', 'health')) return json(200, { status: 'ok', extensions: catalog.extensions.length });

    if (is('GET', 'v1', 'catalog', 'extensions')) return json(200, listExtensions(catalog, query));
    if (is('GET', 'v1', 'catalog', 'categories')) return json(200, catalog.categories);
    if (is('GET', 'v1', 'catalog', 'extensions', null)) {
        const extension = findExtension(catalog, segments[3]);
        return extension ? json(200, extension.detail) : json(404, { errors: ['Resource not found.'] });
    }
    if (is('GET', 'v1', 'catalog', 'extensions', null, 'releases')) {
        const extension = findExtension(catalog, segments[3]);
        if (!extension) return json(404, { errors: ['Resource not found.'] });
        return json(200, { extension: extension.summary.slug, latest: extension.releases[0].version, channels: { stable: extension.releases[0].version }, releases: extension.releases });
    }

    if (is('POST', 'v1', 'instances', 'register')) {
        if (!String(body?.code ?? '').trim()) return json(422, { errors: ['The code field is required.'] });
        return json(body?.previous_token ? 200 : 201, { token: INSTANCE_TOKEN, token_type: 'Bearer', instance: instanceRecord(catalog, body), ...(body?.previous_token ? { rotated: true } : {}) });
    }

    if (segments[0] === 'v1' && segments[1] === 'instances' && segments[2] === 'me') {
        if (token !== INSTANCE_TOKEN) return json(401, { errors: ['Unauthenticated.'] });

        if (is('GET', 'v1', 'instances', 'me')) return json(200, { instance: instanceRecord(catalog), licences: catalog.licences });
        if (is('GET', 'v1', 'instances', 'me', 'updates')) {
            return json(200, {
                data: catalog.installed.map(({ extension, release, updateAvailable }) => ({
                    extension: catalog.reference(extension),
                    installed: release,
                    latest: extension.releases[0],
                    update_available: updateAvailable,
                })),
                meta: { console_version: null, api_version: null, channels: ['stable'], updates_available: catalog.installed.filter((entry) => entry.updateAvailable).length },
            });
        }
        if (is('GET', 'v1', 'instances', 'me', 'compatibility')) {
            return json(200, {
                data: catalog.installed.map(({ extension, release }) => ({ extension: catalog.reference(extension), installed: release, installed_compatible: true, compatible: extension.releases[0], action: 'keep' })),
                meta: { console_version: query.console ?? null, api_version: query.api ?? null, blocked: 0 },
            });
        }
        if (is('POST', 'v1', 'instances', 'me', 'installs')) {
            const extension = findExtension(catalog, String(body?.extension ?? ''));
            if (!extension) return json(404, { errors: ['Resource not found.'] });
            const status = ['installing', 'installed', 'failed'].includes(body?.status) ? body.status : 'installing';
            const at = daysBefore(catalog.now, 0);
            return json(200, {
                install: { id: publicId('install', extension.summary.slug), extension: catalog.reference(extension), release: extension.releases[0], status, installed_by: body?.installed_by ?? null, error: null, started_at: at, finished_at: status === 'installing' ? null : at, created_at: at },
            });
        }
        if (is('DELETE', 'v1', 'instances', 'me', 'installs', null)) {
            const extension = findExtension(catalog, segments[4]);
            if (!extension) return json(404, { errors: ['Resource not found.'] });
            const at = daysBefore(catalog.now, 0);
            return json(200, {
                install: { id: publicId('install', extension.summary.slug), extension: catalog.reference(extension), release: extension.releases[0], status: 'uninstalled', installed_by: null, error: null, started_at: at, finished_at: at, created_at: at },
                deactivated: 1,
            });
        }
        // Purchases are not part of the showcase: nothing here can take a payment.
        if (segments[3] === 'purchases') return json(503, { errors: ['Checkout is not available on the showcase registry.'] });
    }

    return json(404, { errors: ['Resource not found.'] });
}

function readBody(request) {
    return new Promise((resolve) => {
        let raw = '';
        request.on('data', (chunk) => {
            raw += chunk;
            if (raw.length > 1_000_000) request.destroy();
        });
        request.on('end', () => {
            try {
                resolve(raw ? JSON.parse(raw) : {});
            } catch {
                resolve({});
            }
        });
        request.on('error', () => resolve({}));
    });
}

/** The HTTP server around route(). */
export function createServer(catalog, { log = () => {} } = {}) {
    return createHttpServer(async (request, response) => {
        const url = new URL(request.url ?? '/', 'http://registry');
        const authorization = request.headers.authorization ?? '';
        const result = route(catalog, {
            method: request.method ?? 'GET',
            path: url.pathname,
            query: Object.fromEntries(url.searchParams),
            token: authorization.startsWith('Bearer ') ? authorization.slice(7).trim() : null,
            body: ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method ?? '') ? await readBody(request) : {},
        });
        log(`${request.method} ${url.pathname}${url.search} -> ${result.status}`);
        response.writeHead(result.status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        response.end(JSON.stringify(result.body));
    });
}

/** Read the catalogue file and note which media files exist beside it. */
export function loadCatalog(file, { mediaDir = path.join(path.dirname(file), 'media'), mediaBase } = {}) {
    const data = JSON.parse(readFileSync(file, 'utf8'));
    return buildCatalog(data, { mediaBase, hasMedia: (relative) => existsSync(path.join(mediaDir, relative)) });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const file = process.env.CATALOG ?? '/srv/data/catalog.json';
    const port = Number.parseInt(process.env.PORT ?? '8080', 10);
    const catalog = loadCatalog(file, { mediaDir: process.env.MEDIA_DIR, mediaBase: process.env.MEDIA_BASE });
    createServer(catalog, { log: (line) => console.log(line) }).listen(port, '0.0.0.0', () => {
        console.log(`showcase registry: ${catalog.extensions.length} extensions in ${catalog.categories.length} categories, on :${port}`);
    });
}
