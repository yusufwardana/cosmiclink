import { onBeforeUnmount, reactive } from 'vue';
import client from '../api/client.js';
import { createTrafficCoordinator } from '../modules/traffic/trafficQueries.js';

export function useTrafficAnalytics(baseUrl = '') {
    const state = reactive({
        overview: null,
        rankings: null,
        interfaces: null,
        peakHours: null,
        loading: false,
        rankingLoading: false,
        error: null,
    });
    const coordinator = createTrafficCoordinator(
        (url, options) => client.request(url, options),
        baseUrl,
        state,
    );

    onBeforeUnmount(() => coordinator.abort());

    return {
        state,
        refresh: coordinator.refresh,
        refreshRankings: coordinator.refreshRankings,
    };
}