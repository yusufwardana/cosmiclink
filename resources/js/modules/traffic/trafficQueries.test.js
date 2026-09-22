import test from 'node:test';
import assert from 'node:assert/strict';

import { buildTrafficUrl, createTrafficCoordinator } from './trafficQueries.js';

test('builds encoded analytics queries and omits empty unsupported filters', () => {
    assert.equal(
        buildTrafficUrl('/cosmic', 'rankings', {
            period: '7d', mode: 'HOTSPOT', metric: 'download', top: 20,
            router_id: 7, customer_id: '', connection_id: null, package_id: undefined,
        }),
        '/cosmic/api/v1/traffic/rankings?period=7d&mode=HOTSPOT&metric=download&top=20&router_id=7',
    );
    assert.equal(
        buildTrafficUrl('', 'subscriber-history', { period: 'today', mode: 'STATIC_SIMPLE_QUEUE', identity: 'client a/b' }),
        '/api/v1/traffic/subscriber-history?period=today&mode=STATIC_SIMPLE_QUEUE&identity=client+a%2Fb',
    );
});

test('full refresh requests only the four dashboard read endpoints', async () => {
    const calls = [];
    const coordinator = createTrafficCoordinator(async (url) => {
        calls.push(url);
        return { data: url };
    }, '/base');
    await coordinator.refresh({ period: '30d', mode: 'STATIC_SIMPLE_QUEUE', metric: 'total', top: 10, router_id: 4 });
    assert.deepEqual(calls.map((url) => url.split('?')[0]).sort(), [
        '/base/api/v1/traffic/interface-history',
        '/base/api/v1/traffic/overview',
        '/base/api/v1/traffic/peak-hours',
        '/base/api/v1/traffic/rankings',
    ]);
    assert.equal(coordinator.state.loading, false);
    assert.match(coordinator.state.rankings, /rankings/);
});

test('ranking-only refresh does not duplicate overview requests', async () => {
    const calls = [];
    const coordinator = createTrafficCoordinator(async (url) => {
        calls.push(url);
        return { data: [] };
    });
    await coordinator.refreshRankings({ period: 'today', mode: 'HOTSPOT', metric: 'lowest', top: 20 });
    assert.equal(calls.length, 1);
    assert.match(calls[0], /traffic\/rankings/);
    assert.match(calls[0], /metric=lowest/);
    assert.match(calls[0], /top=20/);
});

test('rapid refresh aborts prior requests and ignores stale results', async () => {
    const pending = [];
    const request = (url, { signal }) => new Promise((resolve) => {
        pending.push({ url, signal, resolve });
    });
    const coordinator = createTrafficCoordinator(request);
    const first = coordinator.refresh({ period: 'today', mode: 'HOTSPOT', metric: 'total', top: 10 });
    const second = coordinator.refresh({ period: '7d', mode: 'HOTSPOT', metric: 'total', top: 10 });
    assert.equal(pending.slice(0, 4).every(({ signal }) => signal.aborted), true);
    pending.slice(4).forEach(({ url, resolve }) => resolve({ data: `new:${url}` }));
    await second;
    pending.slice(0, 4).forEach(({ url, resolve }) => resolve({ data: `old:${url}` }));
    await first;
    assert.match(coordinator.state.overview, /^new:/);
    assert.equal(coordinator.state.error, null);
});