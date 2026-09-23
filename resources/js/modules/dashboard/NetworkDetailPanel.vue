<script setup>
import { computed } from 'vue';
import { formatBytes } from '../traffic/trafficFormatters.js';

const props = defineProps({
    selectedNode:     { type: Object, default: null },
    routers:          { type: Array,  default: () => [] },
    discoveryCounts:  { type: Object, default: () => ({}) },
    interfaceTraffic: { type: Array,  default: () => [] },
    baseUrl:          { type: String, default: '' },
});

const uptime = (s) => {
    const t = Number(s);
    if (!Number.isFinite(t) || t <= 0) return '—';
    const d = Math.floor(t / 86400), h = Math.floor((t % 86400) / 3600), m = Math.floor((t % 3600) / 60);
    if (d > 0) return `${d}d ${h}h`; if (h > 0) return `${h}h ${m}m`; return `${m}m`;
};

const router      = computed(() => props.selectedNode?.type === 'router' ? props.selectedNode.raw : null);
const subscriber  = computed(() => props.selectedNode?.type === 'subscriber' ? props.selectedNode.raw : null);
const primaryRouter = computed(() => props.routers[0] ?? null);
const totalDiscovery = computed(() => Object.values(props.discoveryCounts).reduce((a, b) => Number(a) + Number(b), 0));

const stateClass = (state) => ({
    online: 'ui-status-badge--online', degraded: 'ui-status-badge--degraded', offline: 'ui-status-badge--offline',
})[(state ?? '').toLowerCase()] ?? '';

const trafficUrl = () => props.baseUrl ? `${props.baseUrl}/traffic` : '/traffic';
const stamp = (v) => v?.slice(0, 16).replace('T', ' ') ?? '—';
</script>

<template>
<div class="detail-panel">
    <template v-if="!selectedNode">
        <div class="detail-panel__head">
            <p class="panel__kicker">Network Health</p>
            <h3 class="panel__title">Network health</h3>
        </div>
        <div class="detail-panel__body">
            <template v-if="primaryRouter">
                <div class="spec">
                    <div class="spec__row"><span class="spec__key">Router</span><span class="spec__val">{{ primaryRouter.name }}</span></div>
                    <div class="spec__row"><span class="spec__key">State</span><span class="spec__val"><span class="ui-status-badge" :class="stateClass(primaryRouter.monitoring_state)">{{ primaryRouter.monitoring_state ?? 'unknown' }}</span></span></div>
                    <div v-if="primaryRouter.board"               class="spec__row"><span class="spec__key">Board</span>   <span class="spec__val mono-value">{{ primaryRouter.board }}</span></div>
                    <div v-if="primaryRouter.version"             class="spec__row"><span class="spec__key">RouterOS</span><span class="spec__val mono-value">{{ primaryRouter.version }}</span></div>
                    <div v-if="primaryRouter.cpu_load_percent != null"    class="spec__row"><span class="spec__key">CPU</span>   <span class="spec__val mono-value">{{ primaryRouter.cpu_load_percent }}%</span></div>
                    <div v-if="primaryRouter.memory_used_percent != null" class="spec__row"><span class="spec__key">Memory</span><span class="spec__val mono-value">{{ primaryRouter.memory_used_percent }}%</span></div>
                    <div v-if="primaryRouter.uptime_seconds"      class="spec__row"><span class="spec__key">Uptime</span><span class="spec__val mono-value">{{ uptime(primaryRouter.uptime_seconds) }}</span></div>
                </div>
                <hr class="detail-divider">
                <div class="spec">
                    <div class="spec__row"><span class="spec__key">Discovery</span><span class="spec__val mono-value">{{ totalDiscovery }} resources</span></div>
                    <div v-for="(count, type) in discoveryCounts" :key="type" class="spec__row">
                        <span class="spec__key">{{ type }}</span><span class="spec__val mono-value">{{ count }}</span>
                    </div>
                </div>
                <template v-if="interfaceTraffic.length">
                    <hr class="detail-divider">
                    <p class="panel__kicker" style="margin-top:0">Interfaces (24h)</p>
                    <div class="spec">
                        <div v-for="iface in interfaceTraffic.slice(0,5)" :key="iface.interface_name" class="spec__row">
                            <span class="spec__key">{{ iface.interface_name }}</span>
                            <span class="spec__val mono-value" style="font-size:10px">↑{{ formatBytes(iface.upload_bytes) }} ↓{{ formatBytes(iface.download_bytes) }}</span>
                        </div>
                    </div>
                </template>
            </template>
            <p v-else class="empty-state">No routers configured.</p>
        </div>
    </template>

    <template v-else-if="router">
        <div class="detail-panel__head">
            <p class="panel__kicker">Router details</p>
            <h3 class="panel__title">{{ router.name }}</h3>
            <span class="ui-status-badge" :class="stateClass(router.monitoring_state)">{{ router.monitoring_state ?? 'unknown' }}</span>
        </div>
        <div class="detail-panel__body">
            <div class="spec">
                <div v-if="router.identity"           class="spec__row"><span class="spec__key">Identity</span> <span class="spec__val mono-value">{{ router.identity }}</span></div>
                <div v-if="router.board"              class="spec__row"><span class="spec__key">Board</span>    <span class="spec__val mono-value">{{ router.board }}</span></div>
                <div v-if="router.version"            class="spec__row"><span class="spec__key">RouterOS</span> <span class="spec__val mono-value">{{ router.version }}</span></div>
                <div v-if="router.architecture"       class="spec__row"><span class="spec__key">Arch</span>     <span class="spec__val mono-value">{{ router.architecture }}</span></div>
                <div v-if="router.host"               class="spec__row"><span class="spec__key">Host</span>     <span class="spec__val mono-value">{{ router.host }}</span></div>
                <div v-if="router.cpu_load_percent != null"    class="spec__row"><span class="spec__key">CPU</span>    <span class="spec__val mono-value">{{ router.cpu_load_percent }}%</span></div>
                <div v-if="router.memory_used_percent != null" class="spec__row"><span class="spec__key">Memory</span> <span class="spec__val mono-value">{{ router.memory_used_percent }}%</span></div>
                <div v-if="router.uptime_seconds"     class="spec__row"><span class="spec__key">Uptime</span>  <span class="spec__val mono-value">{{ uptime(router.uptime_seconds) }}</span></div>
                <div v-if="router.observed_at"        class="spec__row"><span class="spec__key">Observed</span><span class="spec__val mono-value" style="font-size:10px">{{ stamp(router.observed_at) }}</span></div>
            </div>
        </div>
    </template>

    <template v-else-if="subscriber">
        <div class="detail-panel__head">
            <p class="panel__kicker">{{ subscriber.resource_type === 'pppoe_account' ? 'PPPoE account' : 'Simple Queue' }}</p>
            <h3 class="panel__title">{{ subscriber.name }}</h3>
            <span class="ui-status-badge">{{ subscriber.management_state }}</span>
        </div>
        <div class="detail-panel__body">
            <div class="spec">
                <div v-if="subscriber.target"       class="spec__row"><span class="spec__key">Target</span>    <span class="spec__val mono-value">{{ subscriber.target }}</span></div>
                <div                                class="spec__row"><span class="spec__key">Type</span>      <span class="spec__val mono-value">{{ subscriber.resource_type }}</span></div>
                <div                                class="spec__row"><span class="spec__key">State</span>     <span class="spec__val mono-value">{{ subscriber.management_state }}</span></div>
                <div v-if="subscriber.last_seen_at" class="spec__row"><span class="spec__key">Last seen</span> <span class="spec__val mono-value" style="font-size:10px">{{ stamp(subscriber.last_seen_at) }}</span></div>
                <template v-if="subscriber.upload_bytes != null">
                    <div class="spec__row"><span class="spec__key">Upload (30d)</span>  <span class="spec__val mono-value">{{ formatBytes(subscriber.upload_bytes) }}</span></div>
                    <div class="spec__row"><span class="spec__key">Download (30d)</span><span class="spec__val mono-value">{{ formatBytes(subscriber.download_bytes) }}</span></div>
                    <p class="console-note" style="margin:4px 0 0">Historical · 30-day period.</p>
                </template>
                <p v-else class="console-note" style="margin:6px 0 0">No traffic data in recent 30 days.</p>
            </div>
            <div class="detail-panel__foot">
                <a :href="trafficUrl()" class="button button--quiet button--sm">Traffic Intelligence →</a>
            </div>
        </div>
    </template>

    <template v-else>
        <div class="detail-panel__head"><h3 class="panel__title">—</h3></div>
        <div class="detail-panel__body"><p class="console-note">Select a node to see details.</p></div>
    </template>
</div>
</template>
