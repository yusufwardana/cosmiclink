import './bootstrap';
import { createApp } from 'vue';
import AppShell from './components/AppShell.vue';
import MonitoringPage from './modules/monitoring/MonitoringPage.vue';

const shell = document.getElementById('cosmiclink-shell');
if (shell) createApp(AppShell, { baseUrl: shell.dataset.baseUrl, csrfToken: shell.dataset.csrfToken, currentRoute: shell.dataset.currentRoute, tenant: shell.dataset.tenant, operator: shell.dataset.operator, simulation: shell.dataset.simulation === 'true' }).mount(shell);

const monitoring = document.getElementById('monitoring-vue');
if (monitoring) createApp(MonitoringPage, { baseUrl: monitoring.dataset.baseUrl }).mount(monitoring);
