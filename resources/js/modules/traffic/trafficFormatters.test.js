import test from 'node:test';
import assert from 'node:assert/strict';

import {
    chronological,
    dedupeRankingRows,
    formatBytes,
    formatHour,
    formatHourLabel,
    formatThroughput,
    rankingState,
} from './trafficFormatters.js';

test('formats traffic volume without confusing it with throughput', () => {
    assert.equal(formatBytes(null), '—');
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(1024), '1 KB');
    assert.equal(formatBytes(1536), '1.5 KB');
    assert.equal(formatBytes(1024 ** 2), '1 MB');
    assert.equal(formatBytes(1024 ** 3), '1 GB');
    assert.equal(formatBytes(1024 ** 4), '1 TB');
});

test('formats network throughput with decimal bit-rate units', () => {
    assert.equal(formatThroughput(null), '—');
    assert.equal(formatThroughput(0), '0 bps');
    assert.equal(formatThroughput(1_000), '1 Kbps');
    assert.equal(formatThroughput(1_500_000), '1.5 Mbps');
    assert.equal(formatThroughput(2_000_000_000), '2 Gbps');
});

test('orders chart points chronologically without mutating input', () => {
    const input = [{ hour: '2026-09-22T11:00:00Z' }, { hour: '2026-09-22T09:00:00Z' }];
    assert.deepEqual(chronological(input).map((point) => point.hour), ['2026-09-22T09:00:00Z', '2026-09-22T11:00:00Z']);
    assert.equal(input[0].hour, '2026-09-22T11:00:00Z');
    assert.equal(formatHour('2026-09-22T09:00:00Z'), '09:00');
    assert.equal(formatHour(null), '—');
});

test('classifies loading error unavailable empty and ready ranking states', () => {
    assert.equal(rankingState({ loading: true }), 'loading');
    assert.equal(rankingState({ error: new Error('failed') }), 'error');
    assert.equal(rankingState({ data: { supported: false, reason: 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE' } }), 'unavailable');
    assert.equal(rankingState({ data: [] }), 'empty');
    assert.equal(rankingState({ data: [{ identity: 'client-a' }] }), 'ready');
});

test('deduplicates hotspot usernames per router while retaining separate routers', () => {
    const rows = [
        { router_id: 1, identity: ' Alice ', total_bytes: 10 },
        { router_id: 1, identity: 'alice', total_bytes: 10 },
        { router_id: 2, identity: 'alice', total_bytes: 20 },
    ];
    assert.deepEqual(dedupeRankingRows(rows, 'HOTSPOT').map((row) => [row.router_id, row.identity]), [[1, ' Alice '], [2, 'alice']]);
    assert.equal(dedupeRankingRows(rows, 'STATIC_SIMPLE_QUEUE').length, 3);
});

test('labels hourly windows with the calendar date so peaks are never ambiguous', () => {
    assert.equal(formatHourLabel('2026-09-22T09:00:00+00:00'), 'Sep 22 · 09:00');
    assert.equal(formatHourLabel('2026-09-22T09:00:00Z'), 'Sep 22 · 09:00');
    assert.equal(formatHourLabel(null), '—');
    assert.equal(formatHourLabel('not-a-date'), '—');
});
