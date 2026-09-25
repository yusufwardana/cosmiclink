<script setup>
import { computed } from 'vue';
import { customerMapStatus } from './networkMapUtils.js';

const props = defineProps({
    router: { type: Object, default: null },
    customer: { type: Object, default: null },
    candidate: { type: Object, default: null },
    locationMode: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    showTelemetry: { type: Boolean, default: true },
});
const emit = defineEmits(['set-location', 'cancel-location', 'save-location', 'close']);

const stateClass = computed(() => ({
    online: 'ui-status-badge--online',
    degraded: 'ui-status-badge--degraded',
    offline: 'ui-status-badge--offline',
})[(props.router?.monitoring_state ?? 'unknown').toLowerCase()] ?? '');
const customerState = computed(() => customerMapStatus(props.customer ?? {}));
const customerStateClass = computed(() => ({
    online: 'ui-status-badge--online',
    degraded: 'ui-status-badge--degraded',
    offline: 'ui-status-badge--offline',
    isolated: 'ui-status-badge--offline',
})[customerState.value] ?? '');
const displayCoordinates = computed(() => props.candidate ?? (props.router ? { latitude: props.router.latitude, longitude: props.router.longitude } : null));
const value = (item) => item === null || item === undefined || item === '' ? '—' : item;
</script>

<template>
    <aside class="network-map-detail panel">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">GIS network map</p>
            <h2 class="panel__title">{{ customer?.name ?? router?.name ?? 'Network resource' }}</h2>
            </div>
            <button class="network-map-detail__close" type="button" aria-label="Close details" @click="emit('close')">×</button>
            <span v-if="router" class="ui-status-badge" :class="stateClass">{{ (router.monitoring_state ?? 'unknown').toUpperCase() }}</span>
            <span v-else-if="customer" class="ui-status-badge" :class="customerStateClass">{{ customerState.toUpperCase() }}</span>
        </div>
        <div class="panel__body">
            <template v-if="customer">
                <div class="network-map-detail__identity"><span class="network-map-detail__code mono-value">{{ customer.code || `CL${String(customer.id).padStart(6, '0')}` }}</span><span class="ui-status-badge" :class="customerStateClass">{{ customerState.toUpperCase() }}</span></div>
                <section class="network-map-detail__section"><h3>Network</h3><div class="spec"><div class="spec__row"><span class="spec__key">Lifecycle</span><span class="spec__val mono-value">{{ (customer.connection_status ?? customer.status ?? 'unknown').toUpperCase() }}</span></div><div class="spec__row"><span class="spec__key">Connection</span><span class="spec__val mono-value">{{ customer.connection_mode ? customer.connection_mode.replace('_', ' ').toUpperCase() : '—' }}</span></div><div class="spec__row"><span class="spec__key">Identity</span><span class="spec__val mono-value">{{ customer.network_identity ?? '—' }}</span></div><div class="spec__row"><span class="spec__key">Router</span><span class="spec__val">{{ customer.router ?? '—' }}</span></div><div class="spec__row"><span class="spec__key">Management</span><span class="spec__val mono-value">{{ customer.management_state ?? 'UNMAPPED' }}</span></div></div></section>
                <section class="network-map-detail__section"><h3>Live monitoring</h3><div class="spec"><div class="spec__row"><span class="spec__key">State</span><span class="spec__val mono-value">{{ customerState.toUpperCase() }}</span></div><div class="spec__row"><span class="spec__key">Activity</span><span class="spec__val mono-value">{{ value(customer.activity_state)?.toString().toUpperCase() }}</span></div><div class="spec__row"><span class="spec__key">Upload</span><span class="spec__val mono-value">{{ customer.upload_bps == null ? '—' : `${customer.upload_bps} bps` }}</span></div><div class="spec__row"><span class="spec__key">Download</span><span class="spec__val mono-value">{{ customer.download_bps == null ? '—' : `${customer.download_bps} bps` }}</span></div><div class="spec__row"><span class="spec__key">Last observed</span><span class="spec__val mono-value">{{ value(customer.live_observed_at?.slice(0, 16).replace('T', ' ')) }}</span></div></div></section>
                <section class="network-map-detail__section"><h3>Location</h3><div class="network-map-detail__coordinates mono-value">{{ displayCoordinates ? `${displayCoordinates.latitude}, ${displayCoordinates.longitude}` : 'Location not set' }}</div></section>
                <div v-if="locationMode" class="network-map-location-editor">
                    <p class="console-note">Click the map to choose a candidate position. Nothing is saved until you confirm.</p>
                    <div v-if="candidate" class="cosmic-terminal cosmic-terminal--compact"><div class="cosmic-terminal__line"><span class="cosmic-terminal__label">candidate latitude</span><span class="cosmic-terminal__value">{{ candidate.latitude.toFixed(6) }}</span></div><div class="cosmic-terminal__line"><span class="cosmic-terminal__label">candidate longitude</span><span class="cosmic-terminal__value">{{ candidate.longitude.toFixed(6) }}</span></div></div>
                    <div class="panel__actions"><button class="button button--primary button--sm" type="button" :disabled="!candidate || saving" @click="emit('save-location')">{{ saving ? 'Saving…' : 'Save location' }}</button><button class="button button--quiet button--sm" type="button" :disabled="saving" @click="emit('cancel-location')">Cancel</button></div>
                </div>
                <div class="panel__actions network-map-detail__actions">
                    <a class="button button--quiet button--sm" :href="`/customers/${customer.id}`">Open Customer</a>
                    <button v-if="!locationMode" class="button button--quiet button--sm" type="button" @click="emit('set-location')">Set Location</button>
                    <a v-if="customer.latitude !== null && customer.longitude !== null" class="button button--quiet button--sm" target="_blank" rel="noreferrer" :href="`https://www.google.com/maps/search/?api=1&query=${customer.latitude},${customer.longitude}`">Google Maps</a>
                </div>
            </template>
            <template v-else-if="router">
                <div class="network-map-detail__identity"><span class="ui-status-badge" :class="stateClass">{{ (router.monitoring_state ?? 'unknown').toUpperCase() }}</span></div>
                <section class="network-map-detail__section"><h3>System</h3><div class="spec"><div class="spec__row"><span class="spec__key">RouterOS</span><span class="spec__val mono-value">{{ value(router.routeros_version) }}</span></div><div class="spec__row"><span class="spec__key">Board</span><span class="spec__val mono-value">{{ value(router.board_name) }}</span></div></div></section>
                <section v-if="showTelemetry" class="network-map-detail__section"><h3>Health</h3><div class="spec"><div class="spec__row"><span class="spec__key">CPU</span><span class="spec__val mono-value">{{ router.cpu_load == null ? '—' : `${router.cpu_load}%` }}</span></div><div class="spec__row"><span class="spec__key">Memory</span><span class="spec__val mono-value">{{ router.memory == null ? '—' : `${router.memory}%` }}</span></div><div class="spec__row"><span class="spec__key">Last observed</span><span class="spec__val mono-value">{{ value(router.observed_at?.slice(0, 16).replace('T', ' ')) }}</span></div></div></section>
                <section class="network-map-detail__section"><h3>Location</h3><div class="network-map-detail__coordinates mono-value">{{ displayCoordinates ? `${displayCoordinates.latitude}, ${displayCoordinates.longitude}` : 'Location not set' }}</div></section>

                <div v-if="locationMode" class="network-map-location-editor">
                    <p class="console-note">Click the map to choose a candidate position. Nothing is saved until you confirm.</p>
                    <div v-if="candidate" class="cosmic-terminal cosmic-terminal--compact">
                        <div class="cosmic-terminal__line"><span class="cosmic-terminal__label">candidate latitude</span><span class="cosmic-terminal__value">{{ candidate.latitude.toFixed(6) }}</span></div>
                        <div class="cosmic-terminal__line"><span class="cosmic-terminal__label">candidate longitude</span><span class="cosmic-terminal__value">{{ candidate.longitude.toFixed(6) }}</span></div>
                    </div>
                    <div class="panel__actions">
                        <button class="button button--primary button--sm" type="button" :disabled="!candidate || saving" @click="emit('save-location')">{{ saving ? 'Saving…' : 'Save location' }}</button>
                        <button class="button button--quiet button--sm" type="button" :disabled="saving" @click="emit('cancel-location')">Cancel</button>
                    </div>
                </div>
                <div v-else class="panel__actions network-map-detail__actions">
                    <button class="button button--quiet button--sm" type="button" @click="emit('set-location')">Set Location</button>
                    <a class="button button--quiet button--sm" href="/routers">View Router</a>
                    <a class="button button--quiet button--sm" href="/network/topology">Open Topology</a>
                    <a v-if="router.latitude !== null && router.longitude !== null" class="button button--quiet button--sm" target="_blank" rel="noreferrer" :href="`https://www.google.com/maps/search/?api=1&query=${router.latitude},${router.longitude}`">Google Maps</a>
                </div>
            </template>
            <p v-else class="empty-state">Select a router to see operational details.</p>
        </div>
    </aside>
</template>