import { ref } from 'vue';
import api from '../api/client';

export function useMonitoring(baseUrl) {
    const payload = ref(null); const loading = ref(false); const error = ref('');
    const load = async () => { loading.value = true; error.value = ''; try { payload.value = await api.get(`${baseUrl}/api/v1/monitoring`); } catch (exception) { error.value = exception.message; } finally { loading.value = false; } };
    const post = async (path, data = {}) => { await api.post(`${baseUrl}${path}`, data); await load(); };
    return { payload, loading, error, load, post };
}