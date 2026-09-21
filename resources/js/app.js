import './bootstrap';
import { createApp } from 'vue';
import AppShell from './components/AppShell.vue';
import MonitoringPage from './modules/monitoring/MonitoringPage.vue';

const shell = document.getElementById('cosmiclink-shell');
if (shell) createApp(AppShell, { baseUrl: shell.dataset.baseUrl, csrfToken: shell.dataset.csrfToken, currentRoute: shell.dataset.currentRoute, tenant: shell.dataset.tenant, operator: shell.dataset.operator, simulation: shell.dataset.simulation === 'true' }).mount(shell);

const monitoring = document.getElementById('monitoring-vue');
if (monitoring) createApp(MonitoringPage, { baseUrl: monitoring.dataset.baseUrl, simulation: monitoring.dataset.simulation === 'true' }).mount(monitoring);

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
