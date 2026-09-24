export function createEditState() {
    return { enabled: false, candidates: new Map() };
}

export function candidateCoordinate(event) {
    return { latitude: Number(event.lat), longitude: Number(event.lng) };
}

export function markCandidate(state, resourceType, id, original, candidate) {
    const next = new Map(state.candidates);
    next.set(`${resourceType}:${id}`, { resourceType, id, original, candidate });
    return { ...state, candidates: next };
}

export function cancelCandidates(state) {
    return { ...state, enabled: false, candidates: new Map() };
}

export function candidateCount(state) {
    return state.candidates.size;
}

export function locationEndpoint(resourceType, id) {
    return resourceType === 'customer'
        ? `/api/v1/network-map/customers/${id}/location`
        : `/api/v1/network-map/routers/${id}/location`;
}

export function unlocatedResourceResults(routers = [], customers = [], term = '') {
    const query = String(term).trim().toLowerCase();
    return [
        ...routers.filter((item) => item.latitude == null || item.longitude == null).map((item) => ({ ...item, type: 'router' })),
        ...customers.filter((item) => item.latitude == null || item.longitude == null).map((item) => ({ ...item, type: 'customer' })),
    ].filter((item) => !query || `${item.name} ${item.code || ''} ${item.network_identity || ''}`.toLowerCase().includes(query)).slice(0, 8);
}

export function placementState() {
    return { active: false, resource: null, candidate: null };
}

export function placementCandidate(state, resource = null, candidate = state.candidate) {
    return {
        active: resource !== null || state.active,
        resource: resource ?? state.resource,
        candidate,
    };
}

export function placementMarkerClass() {
    return 'leaflet-network-placement-marker';
}