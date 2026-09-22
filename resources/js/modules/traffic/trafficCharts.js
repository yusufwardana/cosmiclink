import { formatBytes, formatHourLabel } from './trafficFormatters.js';

const niceSteps = [1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];
const defaultInset = { top: 14, right: 16, bottom: 34, left: 64 };

const round = (value) => Math.round((Number(value) || 0) * 10) / 10;

export function niceMaximum(value) {
    const numeric = Number(value) || 0;
    if (numeric <= 1) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(numeric));
    const mantissa = numeric / magnitude;
    const step = niceSteps.find((candidate) => candidate >= mantissa - 1e-9) ?? 10;
    return Math.round(step * magnitude);
}

const plotFrame = ({ width, height, inset }) => {
    const frame = { ...defaultInset, ...(inset || {}) };
    const plotWidth = Math.max(width - frame.left - frame.right, 1);
    const plotHeight = Math.max(height - frame.top - frame.bottom, 1);

    return {
        x: frame.left,
        y: frame.top,
        w: plotWidth,
        h: plotHeight,
        baseline: frame.top + plotHeight,
    };
};

const numericValue = (row, key) => {
    if (!row) return 0;
    const declared = Number(row[key]);
    if (Number.isFinite(declared)) return declared;
    return (Number(row.upload_bytes) || 0) + (Number(row.download_bytes) || 0);
};

const axisTicks = (plot, maximum) => [
    { value: 0, y: plot.baseline },
    { value: round(maximum / 2), y: round(plot.baseline - plot.h / 2) },
    { value: maximum, y: plot.y },
];

const stepX = (index, count, plot) => (count === 1 ? plot.x + plot.w / 2 : plot.x + (index * plot.w) / (count - 1));

const scaleValue = (value, plot, maximum) => round(plot.baseline - (value / maximum) * plot.h);

const toPath = (coordinates) => coordinates.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x},${y}`).join(' ');

export function lineGeometry({
    points = [],
    width = 640,
    height = 200,
    inset = defaultInset,
    downloadKey = 'download_bytes',
    uploadKey = 'upload_bytes',
} = {}) {
    const plot = plotFrame({ width, height, inset });
    const rows = Array.isArray(points) ? points.filter((point) => point) : [];

    if (rows.length === 0) {
        return { empty: true, plot, points: [], ticks: [], max: 0, downloadPath: '', uploadPath: '', downloadArea: '' };
    }

    const maximum = niceMaximum(Math.max(...rows.map((row) => Math.max(numericValue(row, downloadKey), numericValue(row, uploadKey)))));
    const plotted = rows.map((row, index) => ({
        hour: row.hour ?? row.bucket_started_at ?? null,
        label: formatHourLabel(row.hour ?? row.bucket_started_at),
        upload_bytes: numericValue(row, uploadKey),
        download_bytes: numericValue(row, downloadKey),
        x: round(stepX(index, rows.length, plot)),
        downloadY: scaleValue(numericValue(row, downloadKey), plot, maximum),
        uploadY: scaleValue(numericValue(row, uploadKey), plot, maximum),
    }));

    const downloadPath = toPath(plotted.map((point) => [point.x, point.downloadY]));
    const first = plotted[0].x;
    const last = plotted[plotted.length - 1].x;

    return {
        empty: false,
        plot,
        max: maximum,
        points: plotted,
        ticks: axisTicks(plot, maximum),
        downloadPath,
        uploadPath: toPath(plotted.map((point) => [point.x, point.uploadY])),
        downloadArea: `${downloadPath} L${last},${plot.baseline} L${first},${plot.baseline} Z`,
    };
}

export function barGeometry({
    rows = [],
    width = 640,
    height = 180,
    inset = defaultInset,
    valueKey = 'total_bytes',
    labelKey = 'hour',
} = {}) {
    const plot = plotFrame({ width, height, inset });
    const values = Array.isArray(rows) ? rows.filter((row) => row) : [];

    if (values.length === 0) {
        return { empty: true, plot, bars: [], ticks: [], max: 0, band: 0 };
    }

    const maximum = niceMaximum(Math.max(...values.map((row) => numericValue(row, valueKey))));
    const band = plot.w / values.length;
    const barWidth = round(band * 0.62);

    const bars = values.map((row, index) => {
        const value = numericValue(row, valueKey);
        const barHeight = round((value / maximum) * plot.h);

        return {
            hour: row[labelKey] ?? row.hour ?? row.bucket_started_at ?? null,
            label: formatHourLabel(row[labelKey] ?? row.hour ?? row.bucket_started_at),
            value,
            upload_bytes: numericValue(row, 'upload_bytes'),
            download_bytes: numericValue(row, 'download_bytes'),
            x: round(plot.x + index * band + (band - barWidth) / 2),
            y: round(plot.baseline - barHeight),
            width: barWidth,
            height: barHeight,
        };
    });

    return { empty: false, plot, max: maximum, band, bars, ticks: axisTicks(plot, maximum) };
}

const peakSubject = (key) => (key === 'upload_bytes' ? 'upload' : key === 'download_bytes' ? 'download' : 'total');

export function seriesSummary({ points = [], label = 'Traffic', peakKey = 'total_bytes' } = {}) {
    const rows = Array.isArray(points) ? points.filter((row) => row) : [];
    if (rows.length === 0) return `${label}: no data recorded in this period.`;

    const peak = rows.reduce((best, row) => (numericValue(row, peakKey) > numericValue(best, peakKey) ? row : best), rows[0]);
    const stamp = formatHourLabel(peak.hour ?? peak.bucket_started_at);

    return `${label}: ${rows.length} hourly buckets, peak ${formatBytes(numericValue(peak, peakKey))} ${peakSubject(peakKey)} at ${stamp}.`;
}
