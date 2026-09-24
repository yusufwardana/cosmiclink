import test from 'node:test';
import assert from 'node:assert/strict';
import { candidateFromMapClick, formatCoordinate, initialPickerView } from './customerLocationPickerUtils.js';

test('uses persisted customer coordinates when both are valid', () => {
    assert.deepEqual(initialPickerView({ latitude: -7.893742, longitude: 110.848658 }, { latitude: -7.8, longitude: 110.7, zoom: 13 }), {
        center: [-7.893742, 110.848658],
        zoom: 17,
        hasLocation: true,
    });
});

test('uses tenant GIS defaults without inventing a customer location', () => {
    assert.deepEqual(initialPickerView({ latitude: null, longitude: null }, { latitude: -7.8, longitude: 110.7, zoom: 13 }), {
        center: [-7.8, 110.7],
        zoom: 13,
        hasLocation: false,
    });
});

test('map clicks produce a six-decimal candidate display', () => {
    assert.deepEqual(candidateFromMapClick({ lat: -7.8937419, lng: 110.8486576 }), { latitude: -7.893742, longitude: 110.848658 });
    assert.equal(formatCoordinate(-7.8937419), '-7.893742');
});