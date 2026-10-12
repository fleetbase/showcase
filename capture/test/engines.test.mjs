import assert from 'node:assert/strict';
import test from 'node:test';
import { compareVersions, engineMismatch, satisfies } from '../src/engines.mjs';

test('compareVersions orders numerically, not lexically', () => {
    assert.equal(compareVersions('0.4.25', '0.4.24'), 1);
    assert.equal(compareVersions('0.4.9', '0.4.25'), -1);
    assert.equal(compareVersions('v0.6.72', '0.6.72'), 0);
});

test('satisfies handles the operators manifests use', () => {
    assert.equal(satisfies('0.4.25', '>=0.4.25'), true);
    assert.equal(satisfies('0.4.24', '>=0.4.25'), false);
    assert.equal(satisfies('0.4.24', '<0.4.25'), true);
    assert.equal(satisfies('0.5.0', '<0.4.25'), false);
});

test('an engine that is not installed fails >= and passes <', () => {
    assert.equal(satisfies(undefined, '>=0.4.25'), false);
    assert.equal(satisfies(undefined, '<0.4.25'), true);
});

test('engineMismatch names the engine and version that rule an entry out', () => {
    const versions = { '@fleetbase/storefront-engine': '0.4.24' };
    assert.equal(engineMismatch({ requires: { 'storefront-engine': '<0.4.25' } }, versions), null);
    assert.match(engineMismatch({ requires: { 'storefront-engine': '>=0.4.25' } }, versions), /storefront-engine 0\.4\.24 does not meet >=0\.4\.25/);
    assert.equal(engineMismatch({}, versions), null);
});
