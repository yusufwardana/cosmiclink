<script setup>
import { computed, onMounted, ref } from 'vue';
import { useMonitoring } from '../../composables/useMonitoring';

const props = defineProps({ baseUrl: { type: String, required: true }, simulation: { type: Boolean, default: false } });
const { payload, loading, error, load, post } = useMonitoring(props.baseUrl);
const busy = ref('');

const data = computed(() => payload.value?.data || { summary: {}, routers: { data: [] }, connections: { data: [] }, incidents: { data: [] } });
const list = (value) => value?.data || value || [];
const routers = computed(() => list(data.value.routers));
const connections = computed(() => list(data.value.connections));
const incidents = computed(() => list(data.value.incidents));
const activeOutages = computed(() => incidents.value.filter((incident) => ['detected', 'acknowledged'].includes(incident.status)));
const summary = (group, state) => data.value.summary?.[group]?.[state] || 0;
const state = (value) => (value || 'unknown').toString().toLowerCase();
const metric = (value, suffix = '') => (value === null || value === undefined || value === '' ? '—' : `${value}${suffix}`);
const stamp = (value) => (value ? String(value).replace('T', ' ').slice(0, 16) : '—');
const lastObserved = computed(() => [...routers.value, ...connections.value].map((item) => item.observed_at).filter(Boolean).sort().pop());
const providers = computed(() => [...new Set([...routers.value, ...connections.value].map((item) => item.provider).filter(Boolean))]);

/* Detected → acknowledged → resolved, driven by the incident record only. */
const stages = (incident) => [
    { label: 'Detected', time: incident.detected_at, state: incident.status === 'detected' ? 'hot' : 'done' },
    { label: 'Acknowledged', time: incident.acknowledged_at, state: incident.acknowledged_at ? 'done' : (incident.status === 'detected' ? '' : 'active') },
    { label: 'Resolved', time: incident.resolved_at, state: incident.resolved_at ? 'done' : (incident.status === 'acknowledged' ? 'active' : '') },
];

const run = async (key, path, body = {}) => {
    busy.value = key;
    try { await post(path, body); } catch (exception) { error.value = exception.message; } finally { busy.value = ''; }
};
onMounted(load);
</script>

<template>
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">02</span><span class="eyebrow__sep">//</span>Network diagnostics</p>
            <h1>Monitoring console</h1>
            <p class="page-header__meta">Router and connection health observations, correlated into outage incidents server-side. This console reads and simulates; Laravel owns the rules.</p>
        </div>
        <div class="page-header__aside">
            <button type="button" class="button button--primary button--sm" :disabled="loading" @click="run('check', '/api/v1/monitoring/check')">{{ loading ? 'Checking…' : 'Run monitoring check' }}</button>
        </div>
    </div>

    <div v-if="error" class="alert alert-error" role="alert" aria-live="assertive"><span class="alert__mark">!</span><span>{{ error }}</span></div>

    <div class="stat-grid stat-grid--telemetry">
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Routers online</span>
            <span class="stat-card__value" :data-counter="summary('routers', 'online')">{{ summary('routers', 'online') }}</span>
            <span class="stat-card__hint">of {{ routers.length }} routers observed</span>
        </article>
        <article class="stat-card stat-card--warning">
            <span class="stat-card__label">Routers degraded</span>
            <span class="stat-card__value" :data-counter="summary('routers', 'degraded')">{{ summary('routers', 'degraded') }}</span>
            <span class="stat-card__hint">{{ summary('routers', 'offline') }} offline · {{ summary('routers', 'unknown') }} unknown</span>
        </article>
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Connections online</span>
            <span class="stat-card__value" :data-counter="summary('connections', 'online')">{{ summary('connections', 'online') }}</span>
            <span class="stat-card__hint">{{ summary('connections', 'offline') }} offline of {{ connections.length }} observed</span>
        </article>
        <article class="stat-card stat-card--danger">
            <span class="stat-card__label">Active outages</span>
            <span class="stat-card__value" :data-counter="activeOutages.length">{{ activeOutages.length }}</span>
            <span class="stat-card__hint">{{ incidents.length }} incidents on record</span>
        </article>
    </div>

    <section class="panel panel--diagnostic section-block--tight" aria-labelledby="routers-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Rack 01</p>
                <h2 class="panel__title" id="routers-heading">Router health</h2>
            </div>
            <span class="panel__meta">Last observation {{ stamp(lastObserved) }}</span>
        </div>
        <div class="panel__body panel__body--flush">
            <ul class="rack">
                <li v-for="router in routers" :key="router.subject_id" class="rack__row">
                    <span class="rack__id">
                        <span class="rack__name">{{ router.name || 'Unnamed router' }}</span>
                        <span class="rack__sub">{{ router.provider || 'provider unknown' }}</span>
                    </span>
                    <span class="ui-status-badge" :class="`ui-status-badge--${state(router.health_state)}`">{{ state(router.health_state) }}</span>
                    <span class="rack__field"><span class="rack__field-label">Latency</span><span class="rack__field-value">{{ metric(router.latency_ms, ' ms') }}</span></span>
                    <span class="rack__field"><span class="rack__field-label">Loss</span><span class="rack__field-value">{{ metric(router.packet_loss_percent, '%') }}</span></span>
                    <span class="rack__field"><span class="rack__field-label">Last observed</span><span class="rack__field-value">{{ stamp(router.observed_at) }}</span></span>
                    <span v-if="simulation" class="rack__action">
                        <button type="button" class="button button--quiet button--sm" :disabled="busy === `router-${router.subject_id}`" @click="run(`router-${router.subject_id}`, `/api/v1/monitoring/routers/${router.subject_id}/simulation`, { state: router.health_state === 'offline' ? 'online' : 'offline' })">
                            Set {{ router.health_state === 'offline' ? 'online' : 'offline' }}
                        </button>
                    </span>
                </li>
                <li v-if="!routers.length" class="empty-state">No router observations yet. Run a monitoring check to observe the fabric.</li>
            </ul>
        </div>
    </section>

    <section class="panel panel--diagnostic section-block--tight" aria-labelledby="connections-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Rack 02</p>
                <h2 class="panel__title" id="connections-heading">Connection health</h2>
            </div>
            <span class="panel__meta">{{ connections.length }} provisioned connections</span>
        </div>
        <div class="panel__body panel__body--flush">
            <ul class="rack">
                <li v-for="connection in connections" :key="connection.subject_id" class="rack__row">
                    <span class="rack__id">
                        <span class="rack__name">{{ connection.name || 'Unknown connection' }}</span>
                        <span class="rack__sub">{{ connection.customer || 'unassigned customer' }} · {{ connection.router || 'no router' }}</span>
                    </span>
                    <span class="ui-status-badge" :class="`ui-status-badge--${state(connection.health_state)}`">{{ state(connection.health_state) }}</span>
                    <span class="rack__field"><span class="rack__field-label">Router</span><span class="rack__field-value">{{ connection.router || '—' }}</span></span>
                    <span class="rack__field"><span class="rack__field-label">Last observed</span><span class="rack__field-value">{{ stamp(connection.observed_at) }}</span></span>
                    <span v-if="simulation" class="rack__action">
                        <button type="button" class="button button--quiet button--sm" :disabled="busy === `connection-${connection.subject_id}`" @click="run(`connection-${connection.subject_id}`, `/api/v1/monitoring/connections/${connection.subject_id}/simulation`, { state: connection.health_state === 'offline' ? 'online' : 'offline' })">
                            Set {{ connection.health_state === 'offline' ? 'online' : 'offline' }}
                        </button>
                    </span>
                </li>
                <li v-if="!connections.length" class="empty-state">No connection observations yet. Provision a connection, then run a monitoring check.</li>
            </ul>
        </div>
    </section>

    <section id="outage-incidents" aria-labelledby="incidents-heading">
        <div class="section-heading">
            <div>
                <h2 id="incidents-heading">Outage incidents</h2>
                <p>Detected → acknowledged → resolved, correlated from health observations inside the evidence window.</p>
            </div>
            <span class="mono">{{ activeOutages.length }} active · {{ incidents.length }} total</span>
        </div>
        <p v-if="!incidents.length" class="empty-state">No correlated incidents on record. Observations have not crossed the correlation threshold.</p>
        <article v-for="incident in incidents" :key="incident.id" class="incident" :class="[`incident--${incident.status}`, { 'incident--compact': !activeOutages.includes(incident) } ]">
            <div class="incident__head">
                <span class="ui-status-badge" :class="`ui-status-badge--${incident.status}`">{{ incident.status }}</span>
                <span class="incident__router">{{ incident.router?.name || 'Unassigned router' }}</span>
                <span class="incident__stamp">detected {{ stamp(incident.detected_at) }}</span>
            </div>
            <div class="incident__body">
                <ol class="stage-track" aria-label="Incident lifecycle">
                    <li v-for="stage in stages(incident)" :key="stage.label" class="stage" :class="stage.state ? `stage--${stage.state}` : ''">
                        <span class="stage__dot" aria-hidden="true"></span>
                        <span class="stage__label">{{ stage.label }}</span>
                        <time class="stage__time">{{ stamp(stage.time) }}</time>
                    </li>
                </ol>
                <dl class="meta-list incident-facts">
                    <div><dt>Affected customers</dt><dd class="mono-value">{{ incident.affected_customers ?? 0 }}</dd></div>
                    <div><dt>Affected connections</dt><dd class="mono-value">{{ (incident.affected_connections || []).length }}</dd></div>
                    <div><dt>Correlated observations</dt><dd class="mono-value">{{ incident.correlation_count }}</dd></div>
                </dl>
                <ul v-if="(incident.affected_connections || []).length" class="incident__list">
                    <li v-for="connection in incident.affected_connections.slice(0, 5)" :key="connection.id">
                        <span>{{ connection.customer }}</span>
                        <span class="mono-value">{{ connection.code }}</span>
                        <span class="ui-status-badge" :class="`ui-status-badge--${state(connection.status)}`">{{ state(connection.status) }}</span>
                    </li>
                    <li v-if="incident.affected_connections.length > 5" class="mono-value">+{{ incident.affected_connections.length - 5 }} more connections</li>
                </ul>
            </div>
            <div class="incident__foot">
                <button v-if="incident.status === 'detected'" type="button" class="button button--primary button--sm" :disabled="busy === `incident-${incident.id}`" @click="run(`incident-${incident.id}`, `/api/v1/outages/${incident.id}/acknowledge`)">Acknowledge incident</button>
                <span v-else class="console-note">Acknowledged {{ stamp(incident.acknowledged_at) }}</span>
                <a class="button button--quiet button--sm" :href="`${baseUrl}/monitoring/incidents/${incident.id}`">Incident detail and notifications</a>
            </div>
        </article>
    </section>

    <p class="console-note">Observations are stored audit records. Simulation controls write router and connection simulation state only — no physical device is contacted, and no billing or lifecycle state changes. Providers: <span class="mono-value">{{ providers.join(', ') || '—' }}</span></p>
</template>



