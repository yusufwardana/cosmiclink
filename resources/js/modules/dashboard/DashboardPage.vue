<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useNetworkTopology } from '../../composables/useNetworkTopology.js';
import { formatBytes } from '../traffic/trafficFormatters.js';
import NetworkTopology from './NetworkTopology.vue';
import NetworkDetailPanel from './NetworkDetailPanel.vue';

const props = defineProps({
    baseUrl:             { type: String,  default: '' },
    simulation:          { type: Boolean, default: false },
    activeIncidentCount: { type: Number,  default: 0 },
});

const { state, load } = useNetworkTopology(props.baseUrl);
const selectedNode = ref(null);
const searchQuery  = ref('');
const focusNodeId  = ref(null);
const topoRef      = ref(null);

onMounted(load);

const kpiDiscovery   = computed(() => Object.values(state.discoveryCounts).reduce((a, b) => Number(a) + Number(b), 0));
const kpiSubscribers = computed(() => state.subscriberNodes.length + state.pppoeNodes.length);
const kpiRouter      = computed(() => state.routers[0] ?? null);
const kpiRouterState = computed(() => kpiRouter.value?.monitoring_state ?? 'unknown');

const topSubscribers = computed(() => {
    const nodes = state.subscriberNodes.filter((n) => n.upload_bytes != null || n.download_bytes != null);
    return [...nodes].sort((a, b) => ((b.upload_bytes ?? 0) + (b.download_bytes ?? 0)) - ((a.upload_bytes ?? 0) + (a.download_bytes ?? 0))).slice(0, 10);
});

const onNodeSelected = (node) => { selectedNode.value = node; };
const onNodeCleared  = ()     => { selectedNode.value = null; };
const focusSubscriber = (sub) => {
    selectedNode.value = { id: sub.id, type: 'subscriber', raw: sub };
    topoRef.value?.focusSubscriber(sub.id);
    nextTick(() => {
        focusNodeId.value = sub.id;
        setTimeout(() => { focusNodeId.value = null; }, 100);
    });
};

const stateClass = (s) => ({ online: 'ui-status-badge--online', degraded: 'ui-status-badge--degraded', offline: 'ui-status-badge--offline' })[(s ?? '').toLowerCase()] ?? '';
const stateLabel = (s) => (s ?? 'unknown').toUpperCase();
const uptime = (s) => {
    const t = Number(s);
    if (!Number.isFinite(t) || t <= 0) return '—';
    const d = Math.floor(t / 86400), h = Math.floor((t % 86400) / 3600), m = Math.floor((t % 3600) / 60);
    if (d > 0) return `${d}d ${h}h`; if (h > 0) return `${h}h ${m}m`; return `${m}m`;
};
</script>
<template>
<div>
    <div class="stat-grid stat-grid--telemetry">
        <article class="stat-card" :class="kpiRouterState === 'online' ? 'stat-card--accent' : 'stat-card--danger'">
            <span class="stat-card__label">Router</span>
            <span class="stat-card__value"><span class="ui-status-badge" :class="stateClass(kpiRouterState)">{{ stateLabel(kpiRouterState) }}</span></span>
            <span class="stat-card__hint">{{ kpiRouter?.name ?? '—' }}</span>
        </article>
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Discovery resources</span>
            <span class="stat-card__value">{{ kpiDiscovery }}</span>
            <span class="stat-card__hint">{{ state.discoveryCounts['queue'] ?? 0 }} queues · {{ state.discoveryCounts['pppoe_account'] ?? 0 }} PPPoE</span>
        </article>
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Active network identities</span>
            <span class="stat-card__value">{{ kpiSubscribers }}</span>
            <span class="stat-card__hint">{{ state.subscriberNodes.length }} sub · {{ state.pppoeNodes.length }} PPPoE · {{ state.aggregateCount }} agg</span>
        </article>
        <article class="stat-card" :class="activeIncidentCount > 0 ? 'stat-card--danger' : 'stat-card--accent'">
            <span class="stat-card__label">Live / Recent traffic</span>
            <span class="stat-card__value">{{ kpiRouter?.cpu_load_percent != null ? kpiRouter.cpu_load_percent + '%' : '—' }}</span>
            <span class="stat-card__hint">CPU · {{ kpiRouter?.memory_used_percent != null ? kpiRouter.memory_used_percent + '%' : '—' }} mem · {{ uptime(kpiRouter?.uptime_seconds) }}</span>
        </article>
    </div>
    <div class="noc-map-wrap">
        <section class="noc-map-section panel panel--primary" aria-labelledby="noc-map-heading">
            <div class="panel__head">
                <div><p class="panel__kicker">[NET] Network topology // 01</p><h2 class="panel__title" id="noc-map-heading">Network Map</h2></div>
                <div class="panel__actions">
                    <input v-model="searchQuery" type="search" class="noc-search" placeholder="Search node…" aria-label="Search topology nodes">
                    <span class="panel__meta panel__meta--terminal"><span class="terminal-tag">[{{ state.loading ? 'LOADING' : 'LIVE' }}]</span> {{ state.routers.length }} router · {{ kpiSubscribers }} identities</span>
                </div>
            </div>
            <div class="panel__body panel__body--flush noc-map-body">
                <div v-if="state.loading && !state.routers.length" class="noc-loading"><p class="empty-state">Loading network topology…</p></div>
                <div v-else-if="state.error" class="noc-loading"><p class="empty-state">{{ state.error }}</p></div>
                <NetworkTopology v-else ref="topoRef"
                    :routers="state.routers" :subscriber-nodes="state.subscriberNodes"
                    :pppoe-nodes="state.pppoeNodes" :aggregate-count="state.aggregateCount"
                    :interface-traffic="state.interfaceTraffic"
                    :selected-node-id="focusNodeId" :search-query="searchQuery"
                    @node-selected="onNodeSelected" @node-cleared="onNodeCleared"
                />
            </div>
        </section>
        <aside class="noc-detail-aside">
            <NetworkDetailPanel :selected-node="selectedNode" :routers="state.routers"
                :discovery-counts="state.discoveryCounts" :interface-traffic="state.interfaceTraffic" :base-url="baseUrl"
            />
        </aside>
    </div>

    <!-- Bottom row: top subscribers + interfaces -->
    <div class="ops-grid ops-grid--halves noc-bottom-row">
        <section class="panel" aria-labelledby="noc-top-sub">
            <div class="panel__head">
                <div><p class="panel__kicker">Traffic intelligence // 02</p><h2 class="panel__title" id="noc-top-sub">Top subscribers</h2></div>
                <a class="panel__meta" :href="baseUrl + '/traffic'">All traffic →</a>
            </div>
            <div class="panel__body">
                <p v-if="!topSubscribers.length" class="empty-state">No traffic data in last 30 days.</p>
                <div v-else class="noc-sub-list">
                    <div v-for="sub in topSubscribers" :key="sub.id"
                         class="noc-sub-row" role="button" tabindex="0"
                         @click="focusSubscriber(sub)" @keydown.enter="focusSubscriber(sub)">
                        <span class="noc-sub-name">{{ sub.name }}</span>
                        <span class="noc-sub-target mono-value">{{ sub.target }}</span>
                        <span class="noc-sub-usage mono-value">{{ formatBytes((sub.upload_bytes ?? 0) + (sub.download_bytes ?? 0)) }}</span>
                    </div>
                </div>
                <p class="console-note">30-day aggregated · click row to focus in topology · DISCOVERED state.</p>
            </div>
        </section>
        <section class="panel" aria-labelledby="noc-iface">
            <div class="panel__head">
                <div><p class="panel__kicker">Network interfaces // 03</p><h2 class="panel__title" id="noc-iface">Interface traffic</h2></div>
                <span class="panel__meta">24h</span>
            </div>
            <div class="panel__body">
                <p v-if="!state.interfaceTraffic.length" class="empty-state">No interface traffic in last 24 hours.</p>
                <div v-else class="noc-sub-list">
                    <div v-for="iface in state.interfaceTraffic" :key="iface.interface_name" class="noc-sub-row noc-sub-row--passive">
                        <span class="noc-sub-name">{{ iface.interface_name }}</span>
                        <span class="noc-sub-usage mono-value">↑{{ formatBytes(iface.upload_bytes) }}</span>
                        <span class="noc-sub-usage mono-value">↓{{ formatBytes(iface.download_bytes) }}</span>
                    </div>
                </div>
                <p class="console-note">Interface totals are network volume, not subscriber totals.</p>
            </div>
        </section>
    </div>
</div>
</template>
