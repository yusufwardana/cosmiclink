<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useNetworkTopology } from '../../composables/useNetworkTopology.js';
import NetworkTopology from './NetworkTopology.vue';
import NetworkDetailPanel from './NetworkDetailPanel.vue';

const props = defineProps({ baseUrl: { type: String, default: '' } });
const { state, load } = useNetworkTopology(props.baseUrl);

const selectedNode = ref(null);
const searchQuery = ref('');
const focusNodeId = ref(null);
const topologyRef = ref(null);

onMounted(load);

const identityCount = computed(() => state.subscriberNodes.length);
const selectNode = (node) => { selectedNode.value = node; };
const clearNode = () => { selectedNode.value = null; };
const focusSubscriber = (id) => {
    topologyRef.value?.focusSubscriber(id);
    nextTick(() => {
        focusNodeId.value = id;
        setTimeout(() => { focusNodeId.value = null; }, 100);
    });
};
</script>

<template>
    <section class="topology-workspace" aria-labelledby="topology-map-heading">
        <div class="topology-workspace__toolbar">
            <label class="topology-search">
                <span class="sr-only">Search topology</span>
                <input v-model="searchQuery" type="search" placeholder="Search identity, queue or target…" aria-label="Search topology">
            </label>
            <span class="panel__meta panel__meta--terminal"><span class="terminal-tag">[{{ state.loading ? 'LOADING' : 'LIVE' }}]</span> {{ state.routers.length }} router · {{ identityCount }} identities</span>
        </div>

        <div class="topology-workspace__grid">
            <section class="topology-workspace__canvas panel panel--primary" aria-labelledby="topology-map-heading">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">[NET] Relationship graph</p>
                        <h2 class="panel__title" id="topology-map-heading">Network Topology</h2>
                    </div>
                    <span class="panel__meta">Persisted CosmicLink data</span>
                </div>
                <div class="panel__body panel__body--flush topology-workspace__map">
                    <div v-if="state.loading && !state.routers.length" class="noc-loading"><p class="empty-state">Loading Network Topology…</p></div>
                    <div v-else-if="state.error" class="noc-loading"><p class="empty-state">{{ state.error }}</p></div>
                    <NetworkTopology v-else ref="topologyRef"
                        :routers="state.routers"
                        :subscriber-nodes="state.subscriberNodes"
                        :device-nodes="state.deviceNodes"
                        :access-mode-groups="state.accessModeGroups"
                        :pppoe-nodes="state.pppoeNodes"
                        :aggregate-count="state.aggregateCount"
                        :interface-traffic="state.interfaceTraffic"
                        :selected-node-id="focusNodeId"
                        :search-query="searchQuery"
                        @node-selected="selectNode"
                        @node-cleared="clearNode"
                    />
                </div>
            </section>

            <aside class="topology-workspace__details">
                <NetworkDetailPanel
                    :selected-node="selectedNode"
                    :routers="state.routers"
                    :discovery-counts="state.discoveryCounts"
                    :interface-traffic="state.interfaceTraffic"
                    :base-url="baseUrl"
                />
            </aside>
        </div>

        <p class="console-note topology-workspace__note">Read-only relationship view · subscriber ranking and traffic details remain available in Traffic Intelligence.</p>
    </section>
</template>