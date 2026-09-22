const number = (value, maximumFractionDigits = 1) => new Intl.NumberFormat('en-US', {
    maximumFractionDigits,
}).format(value);

export function formatBytes(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const bytes = Number(value);
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)), units.length - 1);
    return `${number(bytes / (1024 ** index))} ${units[index]}`;
}

export function formatThroughput(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const bits = Number(value);
    if (bits === 0) return '0 bps';
    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    const index = Math.min(Math.floor(Math.log(Math.abs(bits)) / Math.log(1000)), units.length - 1);
    return `${number(bits / (1000 ** index))} ${units[index]}`;
}

export function formatHour(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return `${String(date.getUTCHours()).padStart(2, '0')}:00`;
}

const calendarDate = new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', timeZone: 'UTC' });

// Hourly buckets repeat every day, so an hour alone is ambiguous across 7d and 30d views.
export function formatHourLabel(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return `${calendarDate.format(date).replace(/\s+/g, ' ')} · ${formatHour(value)}`;
}

export function chronological(points = [], key = 'hour') {
    return [...points].sort((left, right) => new Date(left?.[key] ?? 0) - new Date(right?.[key] ?? 0));
}

export function rankingState({ loading = false, error = null, data = null } = {}) {
    if (loading) return 'loading';
    if (error) return 'error';
    if (data?.supported === false) return 'unavailable';
    if (!Array.isArray(data) || data.length === 0) return 'empty';
    return 'ready';
}

export function dedupeRankingRows(rows = [], mode = '') {
    if (mode !== 'HOTSPOT') return rows;
    const seen = new Set();
    return rows.filter((row) => {
        const key = `${row.router_id}\0${String(row.identity ?? '').trim().toLowerCase()}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}