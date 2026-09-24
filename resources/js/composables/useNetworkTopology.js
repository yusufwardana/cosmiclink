import { onBeforeUnmount, reactive } from 'vue';
import client from '../api/client.js';

/**
 * Fetches and holds Network Topology data for the dashboard.
 * Read-only. No RouterOS calls. No mutations.
 */
export function useNetworkTopology(baseUrl = '') {
    const state = reactive({
        loading: false,
        error: null,
        routers: [],
        subscriberNodes: [],
        pppoeNodes: [],
        deviceNodes: [],
        accessModeGroups: [],
        aggregateCount: 0,
        discoveryCounts: {},
        interfaceTraffic: [],
    });

    let controller = null;

    const load = async () => {
        if (controller) controller.abort();
        controller = new AbortController();
        state.loading = true;
        state.error = null;
        try {
            const url = `${baseUrl}/api/v1/topology`;
            const body = await client.request(url, { signal: controller.signal });
            const data = body.data ?? {};
            state.routers           = data.routers ?? [];
            state.subscriberNodes   = data.subscriber_nodes ?? [];
            state.pppoeNodes        = data.pppoe_nodes ?? [];
            state.deviceNodes       = data.device_nodes ?? [];
            state.accessModeGroups  = data.access_mode_groups ?? [];
            state.aggregateCount    = data.aggregate_count ?? 0;
            state.discoveryCounts   = data.discovery_counts ?? {};
            state.interfaceTraffic  = data.interface_traffic ?? [];
        } catch (err) {
            if (err.name !== 'AbortError') state.error = err.message ?? 'Failed to load topology.';
        } finally {
            state.loading = false;
        }
    };

    const abort = () => { if (controller) controller.abort(); };
    onBeforeUnmount(abort);

    return { state, load, abort };
}
