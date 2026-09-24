<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { candidateFromMapClick, formatCoordinate, initialPickerView } from './customerLocationPickerUtils.js';

const ESRI_WORLD_IMAGERY_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const ESRI_ATTRIBUTION = 'Tiles &copy; Esri';
const props = defineProps({
    latitude: { type: [String, Number], default: null },
    longitude: { type: [String, Number], default: null },
    defaultLatitude: { type: [String, Number], default: null },
    defaultLongitude: { type: [String, Number], default: null },
    defaultZoom: { type: [String, Number], default: 1.4 },
});
const open = ref(false);
const candidate = ref(null);
const mapEl = ref(null);
const map = ref(null);
const marker = ref(null);
let tileLayer = null;
let mapClickHandler = null;
const candidateIcon = L.divIcon({
    className: 'customer-location-picker__marker',
    html: '<span aria-hidden="true"></span>',
    iconSize: [28, 36],
    iconAnchor: [14, 36],
});

const currentFormLocation = () => ({
    latitude: document.getElementById('latitude')?.value || props.latitude,
    longitude: document.getElementById('longitude')?.value || props.longitude,
});
const pickerView = () => initialPickerView(currentFormLocation(), { latitude: props.defaultLatitude, longitude: props.defaultLongitude, zoom: props.defaultZoom });
const emit = defineEmits(['use-location']);

const placeCandidate = (value) => {
    candidate.value = { latitude: Number(value.latitude), longitude: Number(value.longitude) };
    if (!map.value) return;
    if (!marker.value) {
        marker.value = L.marker([candidate.value.latitude, candidate.value.longitude], { draggable: true, title: 'Candidate customer location', icon: candidateIcon }).addTo(map.value);
        marker.value.on('dragend', () => {
            const point = marker.value.getLatLng();
            candidate.value = candidateFromMapClick(point);
        });
    } else {
        marker.value.setLatLng([candidate.value.latitude, candidate.value.longitude]);
    }
};

const initializeMap = async () => {
    await nextTick();
    const view = pickerView();
    if (!map.value) {
        map.value = L.map(mapEl.value, { zoomControl: true, attributionControl: true }).setView(view.center, view.zoom);
        tileLayer = L.tileLayer(ESRI_WORLD_IMAGERY_URL, { attribution: ESRI_ATTRIBUTION, maxZoom: 19 }).addTo(map.value);
        mapClickHandler = (event) => {
            if (event.target.closest?.('.leaflet-marker-icon, .leaflet-control-container, .leaflet-control-attribution')) return;
            const rect = mapEl.value.getBoundingClientRect();
            const point = map.value.containerPointToLatLng(L.point(event.clientX - rect.left, event.clientY - rect.top));
            placeCandidate(candidateFromMapClick(point));
        };
        mapEl.value.addEventListener('click', mapClickHandler);
    } else {
        map.value.setView(view.center, view.zoom);
    }
    if (view.hasLocation) placeCandidate({ latitude: view.center[0], longitude: view.center[1] });
    else if (marker.value) { marker.value.remove(); marker.value = null; }
    map.value.invalidateSize();
};

const openPicker = () => {
    candidate.value = null;
    open.value = true;
};
const closePicker = () => {
    open.value = false;
    candidate.value = null;
};
const useLocation = () => {
    if (!candidate.value) return;
    const location = { latitude: formatCoordinate(candidate.value.latitude), longitude: formatCoordinate(candidate.value.longitude) };
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    if (latitudeInput) latitudeInput.value = location.latitude;
    if (longitudeInput) longitudeInput.value = location.longitude;
    emit('use-location', location);
    closePicker();
};

watch(open, (value) => { if (value) initializeMap(); });
onBeforeUnmount(() => { if (mapClickHandler) mapEl.value?.removeEventListener('click', mapClickHandler); tileLayer?.remove(); map.value?.remove(); });
</script>

<template>
    <button class="button button--quiet button--sm customer-location-picker__trigger" type="button" @click="openPicker">📍 Pick on Map</button>
    <Teleport to="body">
        <div v-if="open" class="customer-location-picker" role="dialog" aria-modal="true" aria-labelledby="customer-location-picker-title">
            <div class="customer-location-picker__backdrop" @click="closePicker"></div>
            <section class="customer-location-picker__dialog">
                <header class="customer-location-picker__head"><div><p class="panel__kicker">Customer location</p><h2 id="customer-location-picker-title">Select Customer Location</h2></div><button class="customer-location-picker__close" type="button" aria-label="Close location picker" @click="closePicker">×</button></header>
                <div ref="mapEl" class="customer-location-picker__map"></div>
                <div class="customer-location-picker__footer"><div><span class="panel__kicker">Selected location</span><strong v-if="candidate" class="mono-value">{{ formatCoordinate(candidate.latitude) }}, {{ formatCoordinate(candidate.longitude) }}</strong><span v-else class="console-note">Click the map to place a candidate pin.</span></div><div class="panel__actions"><button class="button button--quiet" type="button" @click="closePicker">Cancel</button><button class="button button--primary" type="button" :disabled="!candidate" @click="useLocation">Use This Location</button></div></div>
            </section>
        </div>
    </Teleport>
</template>