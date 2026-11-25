// The Extensions screens from registry-bridge 0.3.0 are captured against a stand-in for the
// Registry Service (docker/registry/server.mjs) serving seeders/Extensions/catalog.json. These
// tests hold the three together: the catalogue file, what the stand-in answers from it, and
// what capture/manifests/extensions.mjs expects to find on screen.

import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { INSTANCE_TOKEN, compareVersions, loadCatalog, publicId, route } from '../../docker/registry/server.mjs';
import manifest from '../manifests/extensions.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const media = path.join(root, 'seeders/Extensions/media');
const catalog = loadCatalog(path.join(root, 'seeders/Extensions/catalog.json'));

const get = (pathname, query = {}, token = null) => route(catalog, { method: 'GET', path: pathname, query, token });
const names = (response) => response.body.data.map((extension) => extension.name);
const bySlug = (slug) => catalog.extensions.find((extension) => extension.summary.slug === slug);

test('every extension has a unique slug, an icon and the screenshots it names', () => {
    const slugs = catalog.extensions.map((extension) => extension.summary.slug);
    assert.equal(new Set(slugs).size, slugs.length, 'duplicate slug');

    for (const extension of catalog.extensions) {
        const slug = extension.summary.slug;
        assert.equal(extension.summary.icon_url, `/showcase-registry/icons/${slug}.svg`, `[${slug}] has no icon: run seeders/Extensions/build-icons.mjs`);
        for (const screenshot of extension.detail.screenshots) {
            assert.ok(existsSync(path.join(media, screenshot.url.replace('/showcase-registry/', ''))), `[${slug}] names a missing screenshot ${screenshot.url}`);
        }
    }
});

test('no core module is in the catalogue', () => {
    // What ships with Fleetbase is not installed from the registry, so it is never listed there.
    // The console's own modules, and Pallet, which registry-bridge also counts as core.
    const core = ['fleetops', 'storefront', 'ledger', 'iam', 'dev', 'registry-bridge', 'customer-portal', 'pallet', 'ai', 'valhalla', 'vroom'];
    for (const extension of catalog.extensions) {
        assert.ok(!core.includes(extension.summary.slug), `[${extension.summary.slug}] is a core module, not a registry extension`);
    }
});

test('ids are derived from the slug, by the rule RegistryServiceSeeder repeats in PHP', () => {
    // sha1("showcase:extension:shopify"), first ten hex characters.
    assert.equal(publicId('ext', 'extension:shopify'), 'ext_684e95f15c');
    assert.equal(bySlug('shopify').id, 'ext_684e95f15c');
});

test('the catalogue list pages, sorts and filters like the service', () => {
    const first = get('/v1/catalog/extensions');
    assert.equal(first.status, 200);
    assert.deepEqual(first.body.meta, { total: 24, per_page: 24, current_page: 1, last_page: 1, from: 1, to: 24 });
    const second = get('/v1/catalog/extensions', { page: '2', limit: '10' });
    assert.deepEqual(second.body.meta, { total: 24, per_page: 10, current_page: 2, last_page: 3, from: 11, to: 20 });
    assert.equal(get('/v1/catalog/extensions', { page: '3', limit: '10' }).body.data.length, 4);

    // Popular is the default: most installs first.
    const installs = first.body.data.map((extension) => extension.installs_count);
    assert.deepEqual(installs, [...installs].sort((a, b) => b - a));

    // Discover's banner: the staff-featured three, in their order.
    assert.deepEqual(names(get('/v1/catalog/extensions', { featured: '1', sort: 'featured', limit: '3' })), ['Shopify', 'flespi', 'Uber Direct']);

    const telematics = get('/v1/catalog/extensions', { category: 'telematics', sort: 'name' });
    assert.ok(telematics.body.data.every((extension) => extension.category.slug === 'telematics'));
    assert.deepEqual(names(telematics), [...names(telematics)].sort((a, b) => a.localeCompare(b, 'en', { sensitivity: 'base' })));
    // By category id as well as slug: the console sends whichever it holds.
    assert.equal(get('/v1/catalog/extensions', { category: telematics.body.data[0].category.id }).body.meta.total, telematics.body.meta.total);

    assert.ok(get('/v1/catalog/extensions', { pricing: 'paid' }).body.data.every((extension) => extension.is_paid));
    assert.ok(get('/v1/catalog/extensions', { pricing: 'free' }).body.data.every((extension) => !extension.is_paid));
    // A cloud-only listing is not offered to a self-hosted instance's hosting filter.
    assert.ok(!names(get('/v1/catalog/extensions', { hosting: 'self_hosted', limit: '100' })).includes('Managed Optimizer'));
    assert.equal(get('/v1/catalog/extensions', { q: 'no such extension' }).body.meta.total, 0);
});

test('a listing is found by slug, id or package name, with the detail fields', () => {
    const detail = get('/v1/catalog/extensions/flespi');
    assert.equal(detail.status, 200);
    for (const field of ['description', 'screenshots', 'versions', 'requires_extensions', 'highlights', 'setup_steps', 'latest_release', 'packages', 'price', 'publisher', 'category']) {
        assert.ok(field in detail.body, `detail is missing ${field}`);
    }
    assert.deepEqual(detail.body.requires_extensions, [{ package: '@fleetbase/fleetops-engine', name: 'Fleet-Ops', range: '>=0.6.0' }]);
    // A prebuilt console engine on every release: without one the API reports that the console
    // must be rebuilt, and no extension can be installed.
    assert.ok(detail.body.latest_release.dist.sha256);

    assert.equal(get(`/v1/catalog/extensions/${detail.body.id}`).body.slug, 'flespi');
    assert.equal(get(`/v1/catalog/extensions/${encodeURIComponent('@fleetbase/flespi-engine')}`).body.slug, 'flespi');
    assert.equal(get('/v1/catalog/extensions/flespi/releases').body.latest, '0.2.1');

    const missing = get('/v1/catalog/extensions/not-an-extension');
    assert.equal(missing.status, 404);
    assert.deepEqual(missing.body, { errors: ['Resource not found.'] });
});

test('category counts add up to the catalogue', () => {
    const categories = get('/v1/catalog/categories').body;
    assert.equal(
        categories.reduce((sum, category) => sum + category.extensions_count, 0),
        catalog.extensions.length
    );
    assert.ok(categories.every((category) => category.extensions_count > 0), 'a category with nothing in it shows an empty page');
});

test('the instance API needs the token registration issued', () => {
    assert.equal(get('/v1/instances/me').status, 401);
    assert.equal(get('/v1/instances/me', {}, 'flbi_wrong').status, 401);

    const registered = route(catalog, { method: 'POST', path: '/v1/instances/register', body: { code: 'SHOWCASE', name: 'Fleetbase Showcase' } });
    assert.equal(registered.status, 201);
    assert.equal(registered.body.token, INSTANCE_TOKEN);
    assert.equal(registered.body.instance.organization.name, 'Fleetbase Pte Ltd');
    // A second registration sends the stored token and gets the same instance back.
    const again = route(catalog, { method: 'POST', path: '/v1/instances/register', body: { code: 'SHOWCASE', previous_token: INSTANCE_TOKEN } });
    assert.equal(again.status, 200);
    assert.equal(again.body.instance.id, registered.body.instance.id);
    assert.equal(route(catalog, { method: 'POST', path: '/v1/instances/register', body: {} }).status, 422);
});

test('installed, updates and licences come from the same catalogue entries', () => {
    const updates = get('/v1/instances/me/updates', {}, INSTANCE_TOKEN).body;
    const waiting = updates.data.filter((entry) => entry.update_available);
    assert.equal(updates.meta.updates_available, waiting.length);
    for (const entry of updates.data) {
        assert.equal(entry.update_available, compareVersions(entry.latest.version, entry.installed.version) > 0);
    }

    const licences = get('/v1/instances/me', {}, INSTANCE_TOKEN).body.licences;
    const paid = licences.filter((licence) => licence.kind === 'paid').map((licence) => licence.extension.slug);
    assert.deepEqual(paid.sort(), ['driver-scorecards', 'flespi', 'whatsapp']);
});

test('reporting an install changes nothing', () => {
    const before = JSON.stringify(get('/v1/instances/me/updates', {}, INSTANCE_TOKEN).body);
    const report = route(catalog, { method: 'POST', path: '/v1/instances/me/installs', token: INSTANCE_TOKEN, body: { extension: bySlug('shopify').id, version: '1.0.2', status: 'installed' } });
    assert.equal(report.status, 200);
    assert.equal(report.body.install.status, 'installed');
    assert.equal(route(catalog, { method: 'DELETE', path: `/v1/instances/me/installs/${bySlug('lalamove').id}`, token: INSTANCE_TOKEN }).body.install.status, 'uninstalled');
    assert.equal(JSON.stringify(get('/v1/instances/me/updates', {}, INSTANCE_TOKEN).body), before);
});

test('manifest entries are gated on the registry-bridge version, with no clash inside a set', () => {
    const sets = { '>=0.3.0': [], '<0.3.0': [] };
    for (const entry of manifest.entries) {
        const spec = entry.requires?.['registry-bridge-engine'];
        assert.ok(spec in sets, `[${entry.id}] is not gated on registry-bridge-engine`);
        sets[spec].push(entry);
    }

    const ids = manifest.entries.map((entry) => entry.id);
    assert.equal(new Set(ids).size, ids.length, 'duplicate entry id');
    for (const [spec, entries] of Object.entries(sets)) {
        assert.ok(entries.length > 0, `no entries for ${spec}`);
        const files = entries.map((entry) => entry.file);
        assert.equal(new Set(files).size, files.length, `duplicate output filename among the ${spec} entries`);
        for (const entry of entries) {
            assert.ok(entry.url && entry.waitFor, `[${entry.id}] is missing a url or a waitFor selector`);
            if (entry.file.endsWith('-list')) assert.ok(entry.expectRows?.min > 0, `[${entry.id}] is a list view with no expectRows.min`);
        }
    }
});

test('the catalogue holds what each 0.3.0 screen is captured for', () => {
    const entry = (id) => manifest.entries.find((candidate) => candidate.id === id);
    const slugOf = (id) => entry(id).url.split('/').pop();
    const installed = catalog.installed.length;

    // Every extension page in the manifest exists in the catalogue.
    for (const candidate of manifest.entries.filter((e) => typeof e.url === 'string' && e.url.startsWith('/extensions/extension/'))) {
        assert.ok(bySlug(candidate.url.split('/').pop()), `[${candidate.id}] opens an extension that is not in catalog.json`);
    }

    assert.ok(get('/v1/catalog/extensions', { category: slugOf('category') }).body.meta.total >= entry('category').expectRows.min);
    assert.ok(get('/v1/catalog/extensions', { q: 'orders' }).body.meta.total >= entry('search').expectRows.min);
    assert.ok(installed >= entry('installed-list').expectRows.min);
    assert.ok(catalog.installed.filter((item) => item.updateAvailable).length >= entry('updates').expectRows.min);
    assert.ok(catalog.licences.filter((licence) => licence.kind === 'paid').length >= entry('purchased').expectRows.min);
    assert.ok(bySlug(slugOf('extension-details')).detail.screenshots.length >= entry('extension-details').expectRows.min);

    // The states the three other extension pages are named for.
    const install = bySlug(slugOf('extension-install')).entry;
    assert.ok(!install.price && !install.installed, 'the install page needs a free extension that is not installed');
    const paid = bySlug(slugOf('extension-paid')).entry;
    assert.ok(paid.price > 0 && !paid.purchased && !paid.installed, 'the paid page needs an extension that is not bought');
    // Needs a core API newer than any this stack runs, whatever release is being captured.
    assert.equal(bySlug(slugOf('extension-blocked')).summary.compat.api, '>=3.0.0');
    const whatsNew = catalog.installed.find((item) => item.extension.summary.slug === slugOf('extension-whats-new'));
    assert.ok(whatsNew?.updateAvailable && whatsNew.extension.releases.length > 1, "the What's new page needs an installed extension with an update and older versions");
});
