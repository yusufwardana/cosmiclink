import test from 'node:test';
import assert from 'node:assert/strict';
import {
    SUBSCRIBER_BATCH_SIZE,
    hasRecentTraffic,
    radialPoint,
    radialTopologyPositions,
    remainingSubscriberCount,
    subscriberSearchMatch,
    truncateTopologyLabel,
    visibleSubscriberNodes,
} from './networkTopologyUtils.js';

test('radial point uses polar coordinates from the supplied center', () => {
    assert.deepEqual(radialPoint(100, 80, 20, 0), { x: 120, y: 80 });
    assert.deepEqual(radialPoint(100, 80, 20, 90), { x: 100, y: 100 });
});

test('radial topology anchors the router at the canvas center', () => {
    const positions = radialTopologyPositions(800, 600, 4);
    assert.deepEqual(positions.router, { x: 400, y: 300 });
    assert.ok(positions.internet.y < positions.router.y);
    assert.ok(positions['group-queue'].x < positions.router.x);
    assert.ok(positions['group-pppoe'].x > positions.router.x);
    assert.equal(Object.keys(positions).filter((key) => key.startsWith('subscriber-')).length, 4);
});

const nodes = Array.from({ length: 20 }, (_, index) => ({
    id: `queue-${index + 1}`,
    name: index === 17 ? 'Delan' : `subscriber-${index + 1}`,
    target: `10.10.12.${index + 1}/32`,
}));

test('limits expanded subscribers to a predictable first batch', () => {
    assert.equal(visibleSubscriberNodes(nodes).length, SUBSCRIBER_BATCH_SIZE);
    assert.equal(remainingSubscriberCount(nodes), 6);
});

test('keeps a focused subscriber visible even when outside the first batch', () => {
    const visible = visibleSubscriberNodes(nodes, SUBSCRIBER_BATCH_SIZE, 'queue-18');
    assert.equal(visible.length, SUBSCRIBER_BATCH_SIZE);
    assert.equal(visible.at(-1).id, 'queue-18');
});

test('matches search against the persisted queue identity and target', () => {
    assert.equal(subscriberSearchMatch(nodes[17], 'delan'), true);
    assert.equal(subscriberSearchMatch(nodes[17], '10.10.12.18'), true);
    assert.equal(subscriberSearchMatch(nodes[17], 'missing'), false);
});

test('truncates long labels without changing the underlying value', () => {
    assert.equal(truncateTopologyLabel('Rakha0812-hotspot -> 10.10.12.85', 14), 'Rakha0812-hot…');
    assert.equal(truncateTopologyLabel('Delan', 14), 'Delan');
});

test('traffic animation eligibility requires real non-zero traffic', () => {
    assert.equal(hasRecentTraffic([{ upload_bytes: 0, download_bytes: 0 }]), false);
    assert.equal(hasRecentTraffic([{ upload_bytes: null, download_bytes: 12 }]), true);
});