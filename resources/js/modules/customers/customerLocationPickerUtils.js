export function validCoordinatePair(value = {}) {
    if (value.latitude === null || value.latitude === undefined || value.latitude === ''
        || value.longitude === null || value.longitude === undefined || value.longitude === '') {
        return false;
    }
    const latitude = Number(value.latitude);
    const longitude = Number(value.longitude);

    return Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
        && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180;
}

export function initialPickerView(customer = {}, defaults = {}) {
    if (validCoordinatePair(customer)) {
        return {
            center: [Number(customer.latitude), Number(customer.longitude)],
            zoom: 17,
            hasLocation: true,
        };
    }

    const latitude = Number(defaults.latitude);
    const longitude = Number(defaults.longitude);

    return {
        center: [Number.isFinite(latitude) ? latitude : 0, Number.isFinite(longitude) ? longitude : 20],
        zoom: Number.isFinite(Number(defaults.zoom)) ? Number(defaults.zoom) : 1.4,
        hasLocation: false,
    };
}

export function candidateFromMapClick(event = {}) {
    return {
        latitude: Number(Number(event.lat).toFixed(6)),
        longitude: Number(Number(event.lng).toFixed(6)),
    };
}

export function formatCoordinate(value) {
    return Number(value).toFixed(6);
}