import test from 'node:test';
import assert from 'node:assert/strict';

import { barGeometry, lineGeometry, niceMaximum, seriesSummary } from './trafficCharts.js';

const points = [
    { hour: '2026-09-22T07:00:00+00:00', upload_bytes: 250, download_bytes: 1_000 },
    { hour: '2026-09-22T08:00:00+00:00', upload_bytes: 500, download_bytes: 2_000 },
    { hour: '2026-09-22T09:00:00+00:00', upload_bytes: 125, download_bytes: 4_000 },
];

test('rounds the axis maximum up to a readable value without inventing data', () => {
    assert.equal(niceMaximum(0), 1);
    assert.equal(niceMaximum(1), 1);
    assert.equal(niceMaximum(3_700), 4_000);
    assert.equal(niceMaximum(4_125), 5_000);
    assert.equal(niceMaximum(12_400_000), 12_500_000);
});

test('maps chronological traffic history onto a bounded line geometry', () => {
    const chart = lineGeometry({ points, width: 640, height: 200 });
    assert.equal(chart.empty, false);
    assert.equal(chart.max, 4_000);
    assert.deepEqual(chart.plot, { x: 64, y: 14, w: 560, h: 152, baseline: 166 });
    assert.deepEqual(chart.points.map((point) => point.x), [64, 344, 624]);
    assert.deepEqual(chart.points.map((point) => point.downloadY), [128, 90, 14]);
    assert.equal(chart.points.every((point) => point.uploadY <= chart.plot.baseline && point.uploadY >= chart.plot.y), true);
    assert.equal(chart.downloadPath, 'M64,128 L344,90 L624,14');
    assert.equal(chart.uploadPath, 'M64,156.5 L344,147 L624,161.3');
    assert.equal(chart.downloadArea, 'M64,128 L344,90 L624,14 L624,166 L64,166 Z');
    assert.deepEqual(chart.ticks.map((tick) => tick.value), [0, 2_000, 4_000]);
    assert.deepEqual(chart.ticks.map((tick) => tick.y), [166, 90, 14]);
    assert.deepEqual(chart.points.map((point) => point.label), ['Sep 22 · 07:00', 'Sep 22 · 08:00', 'Sep 22 · 09:00']);
});

test('a single observation still renders a readable point instead of a zero-width chart', () => {
    const chart = lineGeometry({ points: [points[0]], width: 640, height: 200 });
    assert.equal(chart.points.length, 1);
    assert.equal(chart.points[0].x, 344);
    assert.equal(chart.downloadPath.includes('NaN'), false);
    assert.equal(chart.uploadPath.includes('NaN'), false);
});

test('empty history reports empty geometry rather than a flat zero line', () => {
    const chart = lineGeometry({ points: [] });
    assert.equal(chart.empty, true);
    assert.equal(chart.points.length, 0);
    assert.equal(chart.downloadPath, '');
    assert.equal(chart.uploadPath, '');
    assert.equal(chart.downloadArea, '');
    assert.equal(chart.max, 0);
});

test('hourly bars keep input order and scale height from the largest bucket', () => {
    const chart = barGeometry({ rows: points, width: 420, height: 160 });
    assert.equal(chart.empty, false);
    assert.equal(chart.bars.length, 3);
    assert.equal(chart.max, 5_000);
    assert.deepEqual(chart.bars.map((bar) => bar.value), [1_250, 2_500, 4_125]);
    assert.deepEqual(chart.bars.map((bar) => bar.label), ['Sep 22 · 07:00', 'Sep 22 · 08:00', 'Sep 22 · 09:00']);
    assert.equal(chart.bars.every((bar) => bar.width > 0 && bar.y >= chart.plot.y - 0.5 && bar.y + bar.height <= chart.plot.baseline + 0.5), true);
    assert.equal(chart.bars[2].height > chart.bars[1].height, true);
    assert.equal(chart.bars[1].height > chart.bars[0].height, true);
    assert.equal(barGeometry({ rows: [] }).empty, true);
    assert.equal(barGeometry({ rows: [] }).bars.length, 0);
});

test('summarises a series for assistive tech instead of leaving the chart unlabelled', () => {
    assert.equal(
        seriesSummary({ points: [], label: 'Interface traffic' }),
        'Interface traffic: no data recorded in this period.',
    );
    assert.equal(
        seriesSummary({ points, label: 'Subscriber traffic', peakKey: 'download_bytes' }),
        'Subscriber traffic: 3 hourly buckets, peak 3.9 KB download at Sep 22 · 09:00.',
    );
    assert.equal(
        seriesSummary({ points, label: 'Subscriber traffic', peakKey: 'total_bytes' }),
        'Subscriber traffic: 3 hourly buckets, peak 4 KB total at Sep 22 · 09:00.',
    );
});
