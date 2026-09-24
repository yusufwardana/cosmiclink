import test from 'node:test';
import assert from 'node:assert/strict';
import { locationSaveState, mapBoundsFromPoints, mapSettingEnabled, networkMapViewState, positionedMapPoints, routerMarkerDisplay, routerMapCoordinate, validCoordinatePair } from './networkMapUtils.js';

test('accepts only persisted coordinate pairs within geographic bounds', () => {
    assert.equal(validCoordinatePair({ latitude: -7.7, longitude: 110.3 }), true);
    assert.equal(validCoordinatePair({ latitude: null, longitude: 110.3 }), false);
    assert.equal(validCoordinatePair({ latitude: 91, longitude: 110.3 }), false);
    assert.equal(validCoordinatePair({ latitude: -7.7, longitude: 181 }), false);
});

test('classifies GIS map loading, error, empty and ready states', () => {
    assert.equal(networkMapViewState({ loading: true }), 'loading');
    assert.equal(networkMapViewState({ error: new Error('failed') }), 'error');
    assert.equal(networkMapViewState({ routers: [] }), 'empty');
    assert.equal(networkMapViewState({ routers: [{ id: 2 }] }), 'ready');
});

test('requires an explicit save after selecting a map candidate', () => {
    assert.equal(locationSaveState(), 'idle');
    assert.equal(locationSaveState({ candidate: { latitude: 1, longitude: 2 } }), 'candidate');
    assert.equal(locationSaveState({ candidate: { latitude: 1, longitude: 2 }, saving: true }), 'saving');
    assert.equal(locationSaveState({ saved: true }), 'saved');
});

test('uses MapLibre longitude-latitude ordering for a persisted router', () => {
    assert.deepEqual(routerMapCoordinate({ latitude: -7.892368, longitude: 110.848553 }), [110.848553, -7.892368]);
});

test('combines Router and Customer points in Leaflet latitude-longitude order', () => {
    const points = positionedMapPoints(
        [{ latitude: -7.892368, longitude: 110.848553 }],
        [{ latitude: -7.893742, longitude: 110.848658 }],
    );
    assert.deepEqual(points, [[-7.892368, 110.848553], [-7.893742, 110.848658]]);
    assert.deepEqual(mapBoundsFromPoints(points), {
        south: -7.893742,
        west: 110.848553,
        north: -7.892368,
        east: 110.848658,
    });
});

test('honors persisted GIS marker settings with safe defaults', () => {
    assert.equal(mapSettingEnabled({ navigation_controls: false }, 'navigation_controls'), false);
    assert.equal(mapSettingEnabled({}, 'navigation_controls'), true);
    assert.deepEqual(routerMarkerDisplay({ show_router_name: false, show_status: false }), { showName: false, showStatus: false });
});