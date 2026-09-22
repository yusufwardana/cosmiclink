<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useTrafficAnalytics } from '../../composables/useTrafficAnalytics.js';
import {
    chronological,
    dedupeRankingRows,
    formatBytes,
    formatHourLabel,
    formatThroughput,
    rankingState,
} from './trafficFormatters.js';
import TrafficHourlyBars from './TrafficHourlyBars.vue';
import TrafficLineChart from './TrafficLineChart.vue';

const props = defineProps({
    baseUrl: { type: String, default: '' },
    filters: { type: Object, default: () => ({ routers: [], customers: [], connections: [], packages: [] }) },
});

const { state, refresh, refreshRankings } = useTrafficAnalytics(props.baseUrl);

const periods = [
    { key: 'today', label: 'Today' },
    { key: '7d', label: '7 Days' },
    { key: '30d', label: '30 Days' },
];
const modes = [
    { key: 'STATIC_SIMPLE_QUEUE', label: 'Static queue' },
    { key: 'HOTSPOT', label: 'Hotspot' },
    { key: 'PPPOE', label: 'PPPoE' },
];
const metrics = [
    { key: 'total', label: 'Total Usage' },
    { key: 'download', label: 'Download' },
    { key: 'upload', label: 'Upload' },
    { key: 'lowest', label: 'Lowest Usage' },
];
const topOptions = [10, 20];

const period = ref('today');
const routerId = ref('');
const customerId = ref('');
const connectionId = ref('');
const packageId = ref('');
const mode = ref('STATIC_SIMPLE_QUEUE');
const metric = ref('total');
const top = ref(10);
const interfaceKey = ref('');

const parameters = computed(() => ({
    period: period.value,
    mode: mode.value,
    metric: metric.value,
    top: top.value,
    router_id: routerId.value,
    customer_id: customerId.value,
    connection_id: connectionId.value,
    package_id: packageId.value,
}));

const fullRefresh = () => refresh(parameters.value);
const rankingsRefresh = () => refreshRankings(parameters.value);

onMounted(fullRefresh);
watch([period, routerId, customerId, connectionId, packageId], fullRefresh);
watch([mode, metric, top], rankingsRefresh);

const modesOverview = computed(() => state.overview?.subscriber_modes ?? {});
const modeMetrics = computed(() => modesOverview.value[mode.value] ?? null);
const overviewUnavailable = computed(() => modeMetrics.value?.supported === false);
const overviewPeak = computed(() => state.overview?.peak_hour?.subscriber_modes?.[mode.value] ?? null);
const peakHistory = computed(() => chronological(state.peakHours?.subscriber_modes?.[mode.value] ?? []));

const overviewLoading = computed(() => state.loading && !state.overview);
const kpi = (value) => (overviewUnavailable.value || !state.overview ? '—' : value);

const rankingRows = computed(() => dedupeRankingRows(Array.isArray(state.rankings) ? state.rankings : [], mode.value));
const rankingStatus = computed(() => rankingState({ loading: state.rankingLoading, error: state.error, data: state.rankings }));
const metricValue = (row) => (metric.value === 'download' ? row.download_bytes : metric.value === 'upload' ? row.upload_bytes : row.total_bytes);

const interfaces = computed(() => (Array.isArray(state.interfaces) ? state.interfaces : []));
const activeInterface = computed(() => {
    if (!interfaces.value.length) return null;
    const found = interfaces.value.find((item) => `${item.router_id} ${item.identity}` === interfaceKey.value);
    return found ?? interfaces.value[0];
});
watch(interfaces, (rows) => {
    if (!rows.length) { interfaceKey.value = ''; return; }
    if (!rows.some((item) => `${item.router_id} ${item.identity}` === interfaceKey.value)) {
        interfaceKey.value = `${rows[0].router_id} ${rows[0].identity}`;
    }
});
const interfaceHistory = computed(() => chronological(activeInterface.value?.history ?? [], 'bucket_started_at'));
const interfacePeak = computed(() => state.overview?.peak_hour?.interfaces ?? null);
const interfacePeakLabel = computed(() => (interfacePeak.value?.hour ? formatHourLabel(interfacePeak.value.hour) : '—'));

const peakHourLabel = computed(() => (overviewPeak.value?.hour ? formatHourLabel(overviewPeak.value.hour) : '—'));
const modeLabel = (key) => modes.find((entry) => entry.key === key)?.label ?? key;
const identityName = (row) => row.customer?.name ?? row.connection?.code ?? row.package?.name ?? 'Unmapped network identity';
</script>

<template>
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">06</span><span class="eyebrow__sep">·</span>Network intelligence</p>
            <h1>Traffic Intelligence</h1>
            <p class="page-header__meta">PostgreSQL analytics · subscriber and interface traffic remain separate</p>
        </div>
        <div class="page-header__aside">
            <button type="button" class="button button--quiet button--sm" :disabled="state.loading" @click="fullRefresh">
                {{ state.loading ? 'Refreshing…' : 'Refresh analytics' }}
            </button>
        </div>
    </div>

    <div v-if="state.error" class="alert alert-error" role="alert" aria-live="assertive">
        <span class="alert__mark">!</span><span>{{ state.error.message || 'Traffic analytics request failed.' }}</span>
    </div>

    <section class="panel section-block--tight" aria-labelledby="traffic-controls">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Scope</p>
                <h2 class="panel__title" id="traffic-controls">Period and filters</h2>
            </div>
            <span class="panel__meta">Read-only analytics</span>
        </div>
        <div class="panel__body">
            <div class="traffic-controls" role="group" aria-label="Traffic period">
                <button
                    v-for="entry in periods"
                    :key="entry.key"
                    type="button"
                    class="button button--sm"
                    :class="period === entry.key ? 'button--primary' : 'button--quiet'"
                    :aria-pressed="period === entry.key ? 'true' : 'false'"
                    @click="period = entry.key"
                >{{ entry.label }}</button>
            </div>
            <div class="traffic-controls traffic-controls--filters">
                <label>Router
                    <select v-model="routerId">
                        <option value="">All routers</option>
                        <option v-for="router in props.filters.routers" :key="router.id" :value="router.id">{{ router.name }}</option>
                    </select>
                </label>
                <label>Customer
                    <select v-model="customerId">
                        <option value="">All customers</option>
                        <option v-for="customer in props.filters.customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                    </select>
                </label>
                <label>Connection
                    <select v-model="connectionId">
                        <option value="">All connections</option>
                        <option v-for="connection in props.filters.connections" :key="connection.id" :value="connection.id">{{ connection.code }} · {{ connection.customer_name }}</option>
                    </select>
                </label>
                <label>Package
                    <select v-model="packageId">
                        <option value="">All packages</option>
                        <option v-for="entry in props.filters.packages" :key="entry.id" :value="entry.id">{{ entry.name }}</option>
                    </select>
                </label>
            </div>
        </div>
    </section>

    <div class="stat-grid stat-grid--telemetry">
        <article class="stat-card"><span class="stat-card__label">Total volume</span><span class="stat-card__value">{{ kpi(formatBytes(modeMetrics?.total_bytes)) }}</span><span class="stat-card__hint">{{ modeLabel(mode) }} · {{ periods.find((entry) => entry.key === period)?.label }}</span></article>
        <article class="stat-card"><span class="stat-card__label">Download volume</span><span class="stat-card__value">{{ kpi(formatBytes(modeMetrics?.download_bytes)) }}</span><span class="stat-card__hint">{{ modeLabel(mode) }}</span></article>
        <article class="stat-card"><span class="stat-card__label">Upload volume</span><span class="stat-card__value">{{ kpi(formatBytes(modeMetrics?.upload_bytes)) }}</span><span class="stat-card__hint">{{ modeLabel(mode) }}</span></article>
        <article class="stat-card"><span class="stat-card__label">Average Throughput</span><span class="stat-card__value">{{ kpi(formatThroughput(modeMetrics?.average_throughput_bps)) }}</span><span class="stat-card__hint">Active 5-minute buckets</span></article>
        <article class="stat-card"><span class="stat-card__label">Peak Throughput</span><span class="stat-card__value">{{ kpi(formatThroughput(modeMetrics?.peak_throughput_bps)) }}</span><span class="stat-card__hint">{{ peakHourLabel }}</span></article>
        <article class="stat-card"><span class="stat-card__label">Active buckets</span><span class="stat-card__value">{{ kpi(modeMetrics?.active_bucket_count ?? 0) }}</span><span class="stat-card__hint">5-minute samples</span></article>
    </div>

    <p v-if="overviewUnavailable" class="console-note" role="status">AUTHORITATIVE_TRAFFIC_UNAVAILABLE for {{ modeLabel(mode) }} — unsupported authoritative traffic is never displayed as zero.</p>
    <p v-else-if="overviewLoading" class="empty-state">Loading traffic intelligence.</p>
    <section v-if="!overviewUnavailable" class="panel section-block--tight" aria-labelledby="traffic-history">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Subscriber history</p>
                <h2 class="panel__title" id="traffic-history">Upload and download history</h2>
            </div>
            <span class="panel__meta">{{ modeLabel(mode) }} · {{ peakHourLabel }}</span>
        </div>
        <div class="panel__body">
            <TrafficLineChart v-if="peakHistory.length" :points="peakHistory" :label="`${modeLabel(mode)} traffic`" />
            <p v-else class="empty-state">No history for this mode in this period — no historical data yet.</p>
            <p class="console-note">Chronological hourly buckets. AUTHORITATIVE_TRAFFIC_UNAVAILABLE modes are excluded rather than zeroed.</p>
        </div>
    </section>

    <section class="panel section-block--tight" aria-labelledby="traffic-rankings">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Subscribers</p>
                <h2 class="panel__title" id="traffic-rankings">Top subscribers</h2>
            </div>
            <span class="panel__meta">{{ rankingRows.length }} ranked</span>
        </div>
        <div class="panel__body">
            <div class="traffic-controls" role="group" aria-label="Ranking controls">
                <label>Mode
                    <select v-model="mode">
                        <option v-for="entry in modes" :key="entry.key" :value="entry.key">{{ entry.label }}</option>
                    </select>
                </label>
                <label>Metric
                    <select v-model="metric">
                        <option v-for="entry in metrics" :key="entry.key" :value="entry.key">{{ entry.label }}</option>
                    </select>
                </label>
                <label>Top
                    <select v-model.number="top">
                        <option v-for="count in topOptions" :key="count" :value="count">{{ count }}</option>
                    </select>
                </label>
            </div>
            <p v-if="rankingStatus === 'unavailable'" class="console-note" role="status">AUTHORITATIVE_TRAFFIC_UNAVAILABLE for {{ modeLabel(mode) }} — this mode is not billed from authoritative buckets.</p>
            <p v-else-if="rankingStatus === 'loading'" class="empty-state">Loading rankings.</p>
            <p v-else-if="rankingStatus === 'error'" class="empty-state" role="alert">Rankings failed to load — retry the analytics refresh.</p>
            <p v-else-if="rankingStatus === 'empty'" class="empty-state">No ranked subscribers in this period — no historical data yet.</p>
            <div v-else class="table-scroll">
                <table>
                    <thead><tr><th scope="col">Identity</th><th scope="col">Mapping</th><th scope="col">Upload</th><th scope="col">Download</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="row in rankingRows" :key="`${row.router_id}-${row.identity}`">
                            <td><span class="mono-value">{{ row.identity }}</span><span class="cell-sub">Router {{ row.router_id }}</span></td>
                            <td>
                                <span v-if="row.mapped" class="ui-status-badge ui-status-badge--online">mapped</span>
                                <span v-else class="ui-status-badge">Unmapped network identity</span>
                                <span class="cell-sub">{{ identityName(row) }}</span>
                            </td>
                            <td class="mono-value">{{ formatBytes(row.upload_bytes) }}</td>
                            <td class="mono-value">{{ formatBytes(row.download_bytes) }}</td>
                            <td class="mono-value">{{ formatBytes(metricValue(row)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="mode === 'HOTSPOT'" class="console-note">Hotspot shows one Hotspot identity row per router — usernames repeat across routers and are never merged.</p>
        </div>
    </section>
    <section class="panel section-block--tight" aria-labelledby="traffic-peak">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Peaks</p>
                <h2 class="panel__title" id="traffic-peak">Peak hours</h2>
            </div>
            <span class="panel__meta">{{ modeLabel(mode) }}</span>
        </div>
        <div class="panel__body">
            <TrafficHourlyBars v-if="peakHistory.length" :rows="peakHistory" :label="`${modeLabel(mode)} peak hours`" />
            <p v-else class="empty-state">No peak hours recorded in this period — no historical data yet.</p>
            <p class="console-note">Peak hour {{ peakHourLabel }} for {{ modeLabel(mode) }}. Hourly windows carry the calendar date so peaks stay unambiguous.</p>
        </div>
    </section>

    <section class="panel section-block--tight traffic-interfaces" aria-labelledby="traffic-interfaces">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Network / interface traffic</p>
                <h2 class="panel__title" id="traffic-interfaces">Interfaces</h2>
            </div>
            <span class="panel__meta">{{ interfaces.length }} interfaces</span>
        </div>
        <div class="panel__body">
            <p class="console-note">Interface traffic is network traffic, not customer totals — interface volume is never combined with subscriber KPIs.</p>
            <div class="traffic-controls">
                <label>Interface
                    <select v-model="interfaceKey">
                        <option v-for="item in interfaces" :key="`${item.router_id}-${item.identity}`" :value="`${item.router_id} ${item.identity}`">{{ item.name }} · {{ item.identity }}</option>
                    </select>
                </label>
                <span class="panel__meta">Peak {{ interfacePeakLabel }} · {{ formatBytes(interfacePeak?.total_bytes) }}</span>
            </div>
            <TrafficLineChart v-if="interfaceHistory.length" :points="interfaceHistory" label="Interface traffic" />
            <p v-else class="empty-state">No interface history in this period — no historical data yet.</p>
        </div>
    </section>

    <p class="console-note">PostgreSQL analytics · subscriber and interface traffic remain separate · AUTHORITATIVE_TRAFFIC_UNAVAILABLE is shown explicitly; unavailable traffic is never displayed as zero.</p>
</template>
