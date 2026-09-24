import { onBeforeUnmount, reactive } from 'vue';
import client from '../api/client.js';

export function useNetworkMap(baseUrl = '') {
    const state = reactive({
        loading: false,
        saving: false,
        error: null,
        routers: [],
        unlocatedRouters: [],
        customers: [],
        unlocatedCustomers: [],
        settings: null,
        lastUpdatedAt: null,
        stale: false,
    });
    let controller = null;

    const load = async ({ preserveError = false } = {}) => {
        controller?.abort();
        controller = new AbortController();
        state.loading = true;
        if (!preserveError) state.error = null;
        try {
            const response = await client.request(`${baseUrl}/api/v1/network-map`, { signal: controller.signal });
            state.routers = response.data?.routers ?? [];
            state.unlocatedRouters = response.data?.unlocated_routers ?? [];
            state.customers = response.data?.customers ?? [];
            state.unlocatedCustomers = response.data?.unlocated_customers ?? [];
            state.settings = response.data?.settings ?? null;
            state.lastUpdatedAt = new Date();
            state.stale = false;
        } catch (error) {
            if (error.name !== 'AbortError') {
                state.error = error.message ?? 'Unable to load network map.';
                state.stale = state.routers.length > 0 || state.customers.length > 0;
            }
        } finally {
            state.loading = false;
        }
    };

    const saveLocation = async (routerId, latitude, longitude) => {
        return saveResourceLocation('router', routerId, latitude, longitude);
    };

    const saveResourceLocation = async (resourceType, resourceId, latitude, longitude) => {
        state.saving = true;
        state.error = null;
        try {
            const resourcePath = resourceType === 'customer' ? 'customers' : 'routers';
            await client.request(`${baseUrl}/api/v1/network-map/${resourcePath}/${resourceId}/location`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ latitude, longitude }),
            });
            await load();
        } catch (error) {
            state.error = error.message ?? 'Unable to save router location.';
            throw error;
        } finally {
            state.saving = false;
        }
    };

    const abort = () => controller?.abort();
    onBeforeUnmount(abort);

    return { state, load, saveLocation, saveResourceLocation, abort };
}