import test from 'node:test';
import assert from 'node:assert/strict';
import { candidateCoordinate, cancelCandidates, candidateCount, createEditState, locationEndpoint, markCandidate, placementCandidate, placementMarkerClass, placementState, unlocatedResourceResults } from './networkMapEditUtils.js';

test('edit mode starts read-only and candidates use Leaflet lat/lng ordering', () => {
    const state = createEditState();
    assert.equal(state.enabled, false);
    assert.deepEqual(candidateCoordinate({ lat: -7.8938, lng: 110.8485 }), { latitude: -7.8938, longitude: 110.8485 });
});

test('candidate changes are local until Save and Cancel clears them', () => {
    let state = { ...createEditState(), enabled: true };
    state = markCandidate(state, 'customer', 11, { latitude: -7.893742, longitude: 110.848658 }, { latitude: -7.8938, longitude: 110.8485 });
    assert.equal(candidateCount(state), 1);
    assert.equal(candidateCount(cancelCandidates(state)), 0);
});

test('resource-specific location endpoints remain separate', () => {
    assert.equal(locationEndpoint('router', 2), '/api/v1/network-map/routers/2/location');
    assert.equal(locationEndpoint('customer', 11), '/api/v1/network-map/customers/11/location');
});

test('finds unlocated routers and customers without inventing coordinates', () => {
    const results = unlocatedResourceResults(
        [{ id: 1, name: 'MikroTik 10.10.12.1', latitude: null, longitude: null }],
        [{ id: 2, name: 'MAHLOR', code: 'CL000002', latitude: null, longitude: null }],
        'mahlor',
    );
    assert.deepEqual(results, [{ id: 2, name: 'MAHLOR', code: 'CL000002', type: 'customer', latitude: null, longitude: null }]);
    assert.deepEqual(unlocatedResourceResults([{ id: 1, name: 'Router', latitude: null, longitude: null }], [], 'router'), [{ id: 1, name: 'Router', type: 'router', latitude: null, longitude: null }]);
});

test('placement state stays local until an explicit map click and supports cancel', () => {
    const initial = placementState();
    assert.equal(initial.active, false);
    const active = placementCandidate(initial, { type: 'router', id: 1, name: 'Router' });
    assert.deepEqual(active.resource, { type: 'router', id: 1, name: 'Router' });
    assert.equal(active.candidate, null);
    const placed = placementCandidate(active, null, { latitude: -7.8, longitude: 110.3 });
    assert.deepEqual(placed.candidate, { latitude: -7.8, longitude: 110.3 });
    assert.equal(placementState().candidate, null);
});

test('placement markers use an inline asset-free Leaflet class', () => {
    assert.equal(placementMarkerClass(), 'leaflet-network-placement-marker');
});