<script setup>
import { computed, onMounted, ref } from 'vue';
import { useNetworkTopology } from '../../composables/useNetworkTopology.js';
import NetworkTopology from './NetworkTopology.vue';
import NetworkDetailPanel from './NetworkDetailPanel.vue';

const props = defineProps({ baseUrl: { type: String, default: '' } });
const { state, load } = useNetworkTopology(props.baseUrl);
const selectedNode = ref(null);
const searchQuery = ref('');
const topologyRef = ref(null);
const identityCount = computed(() => state.subscriberNodes.length + state.pppoeNodes.length);
const selectNode = (node) => { selectedNode.value = node; };
const clearNode = () => { selectedNode.value = null; };
onMounted(load);
</script>

<template>
    <section class="dashboard-topology-panel panel panel--primary" aria-labelledby="dashboard-topology-map-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Network relationships // 02</p>
                <h2 class="panel__title" id="dashboard-topology-map-heading">Network Topology</h2>
                <p class="panel__meta">Logical network relationships</p>
            </div>
            <div class="panel__actions">
                <input v-model="searchQuery" class="noc-search dashboard-topology-panel__search" type="search" placeholder="Search identity…" aria-label="Search topology">
                <span class="panel__meta panel__meta--terminal"><span class="terminal-tag">[{{ state.loading ? 'LOADING' : 'LIVE' }}]</span> {{ identityCount }} identities</span>
                <a class="button button--quiet button--sm" :href="`${baseUrl}/network/topology`">Open Topology</a>
            </div>
        </div>
        <div class="panel__body panel__body--flush dashboard-topology-panel__body">
            <div v-if="state.loading && !state.routers.length" class="noc-loading"><p class="empty-state">Loading topology…</p></div>
            <div v-else-if="state.error" class="noc-loading"><p class="empty-state">{{ state.error }}</p></div>
            <div v-else class="dashboard-topology-panel__grid">
                <NetworkTopology ref="topologyRef" :routers="state.routers" :subscriber-nodes="state.subscriberNodes" :pppoe-nodes="state.pppoeNodes" :aggregate-count="state.aggregateCount" :interface-traffic="state.interfaceTraffic" :search-query="searchQuery" @node-selected="selectNode" @node-cleared="clearNode" />
                <NetworkDetailPanel :selected-node="selectedNode" :routers="state.routers" :discovery-counts="state.discoveryCounts" :interface-traffic="state.interfaceTraffic" :base-url="baseUrl" />
            </div>
        </div>
    </section>
</template>