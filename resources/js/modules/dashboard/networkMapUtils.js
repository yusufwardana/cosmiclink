export function validCoordinatePair(router) {
    if (router?.latitude === null || router?.latitude === undefined || router?.longitude === null || router?.longitude === undefined || router?.latitude === '' || router?.longitude === '') {
        return false;
    }
    const latitude = Number(router?.latitude);
    const longitude = Number(router?.longitude);

    return Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
        && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180;
}

export function networkMapViewState({ loading = false, error = null, routers = [] } = {}) {
    if (loading) return 'loading';
    if (error) return 'error';
    if (routers.length === 0) return 'empty';
    return 'ready';
}

export function locationSaveState({ candidate = null, saving = false, saved = false } = {}) {
    if (saving) return 'saving';
    if (saved) return 'saved';
    if (candidate) return 'candidate';
    return 'idle';
}

export function routerMapCoordinate(router) {
    return [Number(router?.longitude), Number(router?.latitude)];
}

export function positionedMapPoints(routers = [], customers = []) {
    return [
        ...routers.filter(validCoordinatePair).map((router) => [Number(router.latitude), Number(router.longitude)]),
        ...customers.filter(validCoordinatePair).map((customer) => [Number(customer.latitude), Number(customer.longitude)]),
    ];
}

export function mapBoundsFromPoints(points = []) {
    if (!points.length) return null;
    const latitudes = points.map(([latitude]) => latitude);
    const longitudes = points.map(([, longitude]) => longitude);

    return {
        south: Math.min(...latitudes),
        west: Math.min(...longitudes),
        north: Math.max(...latitudes),
        east: Math.max(...longitudes),
    };
}

export function mapSettingEnabled(settings, key, fallback = true) {
    return settings?.[key] === undefined ? fallback : settings[key] !== false;
}

export function routerMarkerDisplay(settings) {
    return {
        showName: mapSettingEnabled(settings, 'show_router_name'),
        showStatus: mapSettingEnabled(settings, 'show_status'),
    };
}