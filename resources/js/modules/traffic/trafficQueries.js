const endpoints = {
    overview: 'overview',
    rankings: 'rankings',
    interfaces: 'interface-history',
    peakHours: 'peak-hours',
    subscriberHistory: 'subscriber-history',
};

const allowedParameters = new Set([
    'period', 'router_id', 'customer_id', 'connection_id', 'package_id',
    'mode', 'metric', 'top', 'identity',
]);

export function buildTrafficUrl(baseUrl = '', endpoint, parameters = {}) {
    const path = endpoints[endpoint] ?? endpoint;
    const query = new URLSearchParams();
    Object.entries(parameters).forEach(([key, value]) => {
        if (allowedParameters.has(key) && value !== '' && value !== null && value !== undefined) {
            query.set(key, String(value));
        }
    });
    const prefix = String(baseUrl).replace(/\/$/, '');
    return `${prefix}/api/v1/traffic/${path}?${query.toString()}`;
}

const initialState = () => ({
    overview: null,
    rankings: null,
    interfaces: null,
    peakHours: null,
    loading: false,
    rankingLoading: false,
    error: null,
});

export function createTrafficCoordinator(request, baseUrl = '', state = initialState()) {
    let controller = null;
    let generation = 0;

    const rankingParameters = (parameters) => ({
        ...parameters,
        mode: parameters.mode,
        metric: parameters.metric,
        top: parameters.top,
    });

    const run = async (operations, loadingKey) => {
        controller?.abort();
        controller = new AbortController();
        const requestGeneration = ++generation;
        state[loadingKey] = true;
        state.error = null;
        try {
            const results = await Promise.all(operations.map(async ([key, endpoint, parameters]) => {
                const response = await request(buildTrafficUrl(baseUrl, endpoint, parameters), { signal: controller.signal });
                return [key, response?.data ?? null];
            }));
            if (requestGeneration !== generation) return;
            results.forEach(([key, value]) => { state[key] = value; });
        } catch (error) {
            if (requestGeneration !== generation || error?.name === 'AbortError') return;
            state.error = error;
        } finally {
            if (requestGeneration === generation) state[loadingKey] = false;
        }
    };

    return {
        state,
        refresh(parameters) {
            return run([
                ['overview', 'overview', parameters],
                ['rankings', 'rankings', rankingParameters(parameters)],
                ['interfaces', 'interfaces', parameters],
                ['peakHours', 'peakHours', parameters],
            ], 'loading');
        },
        refreshRankings(parameters) {
            return run([
                ['rankings', 'rankings', rankingParameters(parameters)],
            ], 'rankingLoading');
        },
        abort() {
            generation++;
            controller?.abort();
            state.loading = false;
            state.rankingLoading = false;
        },
    };
}