import './bootstrap';
import { createApp } from 'vue';
import AppShell from './components/AppShell.vue';
import NetworkTopologyPage from './modules/dashboard/NetworkTopologyPage.vue';
import MonitoringPage from './modules/monitoring/MonitoringPage.vue';
import TrafficIntelligencePage from './modules/traffic/TrafficIntelligencePage.vue';
import CustomerTrafficPanel from './modules/traffic/CustomerTrafficPanel.vue';
import NetworkGisMap from './modules/dashboard/NetworkGisMap.vue';
import CustomerLocationPicker from './modules/customers/CustomerLocationPicker.vue';

const shell = document.getElementById('cosmiclink-shell');
if (shell) createApp(AppShell, { baseUrl: shell.dataset.baseUrl, csrfToken: shell.dataset.csrfToken, currentRoute: shell.dataset.currentRoute, tenant: shell.dataset.tenant, operator: shell.dataset.operator, simulation: shell.dataset.simulation === 'true' }).mount(shell);

const topology = document.getElementById('network-topology-vue');
if (topology) createApp(NetworkTopologyPage, { baseUrl: topology.dataset.baseUrl || '' }).mount(topology);

const gisMap = document.getElementById('network-gis-map-vue');
if (gisMap) createApp(NetworkGisMap, { baseUrl: gisMap.dataset.baseUrl || '', workspace: gisMap.dataset.workspace === 'true' }).mount(gisMap);

const customerLocationPicker = document.getElementById('customer-location-picker');
if (customerLocationPicker) createApp(CustomerLocationPicker, {
    latitude: customerLocationPicker.dataset.latitude || null,
    longitude: customerLocationPicker.dataset.longitude || null,
    defaultLatitude: customerLocationPicker.dataset.defaultLatitude || null,
    defaultLongitude: customerLocationPicker.dataset.defaultLongitude || null,
    defaultZoom: customerLocationPicker.dataset.defaultZoom || 1.4,
}).mount(customerLocationPicker);

const monitoring = document.getElementById('monitoring-vue');
if (monitoring) createApp(MonitoringPage, { baseUrl: monitoring.dataset.baseUrl, simulation: monitoring.dataset.simulation === 'true' }).mount(monitoring);

const traffic = document.getElementById('traffic-intelligence-vue');
if (traffic) {
    let filters = { routers: [], customers: [], connections: [], packages: [] };
    try { filters = { ...filters, ...JSON.parse(traffic.dataset.filters || '{}') }; } catch (e) { /* keep empty */ }
    createApp(TrafficIntelligencePage, { baseUrl: traffic.dataset.baseUrl || '', filters }).mount(traffic);
}

const customerTraffic = document.getElementById('customer-traffic-vue');
if (customerTraffic) {
    let connections = [];
    try { connections = JSON.parse(customerTraffic.dataset.connections || '[]'); } catch (e) { connections = []; }
    createApp(CustomerTrafficPanel, { baseUrl: customerTraffic.dataset.baseUrl || '', connections }).mount(customerTraffic);
}

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const counters = () => document.querySelectorAll('[data-counter]');
const animateCounters = () => {
    counters().forEach((element) => {
        const target = Number(element.dataset.counter);
        if (!Number.isFinite(target)) return;
        if (reduceMotion || target === 0) { element.textContent = String(target); return; }
        const started = performance.now();
        const duration = 420;
        const tick = (now) => {
            const progress = Math.min((now - started) / duration, 1);
            element.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
            if (progress < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
};

const progress = document.querySelector('[data-scroll-progress]');
const updateScrollProgress = () => {
    if (!progress) return;
    const scrollable = document.documentElement.scrollHeight - window.innerHeight;
    const percentage = scrollable > 0 ? Math.round((window.scrollY / scrollable) * 100) : 0;
    progress.style.transform = `scaleX(${percentage / 100})`;
    progress.setAttribute('aria-valuenow', String(percentage));
};
window.addEventListener('scroll', updateScrollProgress, { passive: true });
window.addEventListener('resize', updateScrollProgress, { passive: true });
requestAnimationFrame(() => { animateCounters(); updateScrollProgress(); });