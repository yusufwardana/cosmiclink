const client = {
    async request(url, options = {}) {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) }, credentials: 'same-origin', ...options });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw Object.assign(new Error(body.message || 'API request failed.'), { response, body });
        return body;
    },
    get(url) { return this.request(url); },
    post(url, data = {}) { return this.request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify(data) }); },
};

export default client;