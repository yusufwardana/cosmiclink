<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import client from '../../api/client.js';
import { chronological, formatBytes } from './trafficFormatters.js';
import { buildTrafficUrl } from './trafficQueries.js';
import TrafficLineChart from './TrafficLineChart.vue';

const props = defineProps({
    baseUrl: { type: String, default: '' },
    connections: { type: Array, default: () => [] },
});

const periods = [
    { key: 'today', label: 'Today' },
    { key: '7d', label: '7 Days' },
    { key: '30d', label: '30 Days' },
];

const period = ref('today');
const connectionId = ref(props.connections[0]?.id ?? '');
const loading = ref(false);
const error = ref(null);
const staticHistory = ref([]);
const hotspotHistory = ref([]);

const activeConnection = computed(() => props.connections.find((entry) => String(entry.id) === String(connectionId.value)) ?? null);

const load = async () => {
    if (!activeConnection.value?.identity) {
        staticHistory.value = [];
        hotspotHistory.value = [];
        return;
    }
    loading.value = true;
    error.value = null;
    const controller = new AbortController();
    try {
        const [staticResponse, hotspotResponse] = await Promise.all([
            client.request(buildTrafficUrl(props.baseUrl, 'subscriber-history', { period: period.value, mode: 'STATIC_SIMPLE_QUEUE', identity: activeConnection.value.identity }), { signal: controller.signal }),
            client.request(buildTrafficUrl(props.baseUrl, 'subscriber-history', { period: period.value, mode: 'HOTSPOT', identity: activeConnection.value.identity }), { signal: controller.signal }),
        ]);
        staticHistory.value = chronological(Array.isArray(staticResponse?.data) ? staticResponse.data : [], 'bucket_started_at');
        hotspotHistory.value = chronological(Array.isArray(hotspotResponse?.data) ? hotspotResponse.data : [], 'bucket_started_at');
    } catch (requestError) {
        if (requestError?.name !== 'AbortError') error.value = requestError;
    } finally {
        loading.value = false;
    }
};

onMounted(load);
watch([period, connectionId], load);

const combined = computed(() => {
    const totals = new Map();
    [...staticHistory.value, ...hotspotHistory.value].forEach((bucket) => {
        const key = bucket.bucket_started_at;
        const current = totals.get(key) ?? { bucket_started_at: key, upload_bytes: 0, download_bytes: 0 };
        current.upload_bytes += Number(bucket.upload_bytes) || 0;
        current.download_bytes += Number(bucket.download_bytes) || 0;
        totals.set(key, current);
    });
    return chronological([...totals.values()], 'bucket_started_at');
});
</script>

<template>
    <div v-if="error" class="alert alert-error" role="alert"><span class="alert__mark">!</span><span>{{ error.message || 'Traffic history request failed.' }}</span></div>
    <div class="traffic-controls" role="group" aria-label="Customer traffic period">
        <button
            v-for="entry in periods"
            :key="entry.key"
            type="button"
            class="button button--sm"
            :class="period === entry.key ? 'button--primary' : 'button--quiet'"
            :aria-pressed="period === entry.key ? 'true' : 'false'"
            @click="period = entry.key"
        >{{ entry.label }}</button>
        <label>Connection
            <select v-model="connectionId">
                <option v-for="entry in props.connections" :key="entry.id" :value="entry.id">{{ entry.code }} · {{ entry.identity }}</option>
            </select>
        </label>
    </div>
    <p v-if="loading" class="empty-state">Loading traffic history.</p>
    <p v-else-if="!activeConnection?.identity" class="empty-state">No mapped network identity on this connection — traffic history needs a linked account.</p>
    <template v-else>
        <p class="console-note">Static queue {{ formatBytes(staticHistory.reduce((sum, row) => sum + (Number(row.upload_bytes) || 0) + (Number(row.download_bytes) || 0), 0)) }} · Hotspot {{ formatBytes(hotspotHistory.reduce((sum, row) => sum + (Number(row.upload_bytes) || 0) + (Number(row.download_bytes) || 0), 0)) }} — modes stay separate; PPPoE is never inferred.</p>
        <TrafficLineChart v-if="combined.length" :points="combined" label="Customer traffic" :width="520" :height="170" />
        <p v-else class="empty-state">No history for this connection in this period — no historical data yet.</p>
    </template>
</template>
