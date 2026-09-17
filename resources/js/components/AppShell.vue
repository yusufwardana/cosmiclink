<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({ baseUrl: String, csrfToken: String, currentRoute: String, tenant: String, operator: String, simulation: Boolean });

const mobileOpen = ref(false);
const theme = ref('dark');
const hash = ref('');
const initials = computed(() => (props.operator || 'OP').split(' ').filter(Boolean).map((part) => part[0]).join('').slice(0, 2).toUpperCase());
const href = (path) => `${props.baseUrl || ''}${path}`;
const close = () => { mobileOpen.value = false; };
const setTheme = (value) => {
    theme.value = value;
    document.documentElement.dataset.theme = value;
    window.localStorage.setItem('cosmiclink-theme', value);
};
const toggleTheme = () => setTheme(theme.value === 'dark' ? 'light' : 'dark');
onMounted(() => {
    hash.value = window.location.hash;
    theme.value = document.documentElement.dataset.theme || 'dark';
});

/* 16px stroke glyphs, inline — the shell ships no icon font. */
const icons = {
    dashboard: 'M2.5 7 8 2.8 13.5 7v6.2h-11zM6.4 13.2V9.4h3.2v3.8',
    customers: 'M6.3 7.4a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4ZM2.4 13.4c0-2.1 1.7-3.5 3.9-3.5s3.9 1.4 3.9 3.5M11.9 12.6h2.6M13.2 3.6a2 2 0 0 1 0 3.9',
    invoices: 'M4.2 2.4h7.6v11.2l-2.5-1.4-1.3 1.5-1.3-1.5-2.5 1.4zM6.4 5.6h3.2M6.4 8.1h3.2',
    payments: 'M2.4 5.6h11.2v6.8H2.4zM2.4 8.2h11.2M9.6 12.8l3.2-3.2',
    routers: 'M8 2.6v3.4M4.4 6h7.2v2.2H4.4zM2.6 11h2.2v2.2H2.6zM6.9 11h2.2v2.2H6.9zM11.2 11h2.2v2.2h-2.2zM8 8.2v2.8',
    accounts: 'M8 2.6v3M5.3 5.6h5.4l1.4 4H3.9zM4.1 9.6v1.9h7.8V9.6M6.5 13.4h3',
    monitoring: 'M2.4 8.2h2.4l1.5-4.2 2.3 8.2 1.6-4h3.4',
    outages: 'M8 3.2 14 13.1H2zM8 6.6v3.1M8 11.4v.5',
    messages: 'M2.4 3.9h11.2v6.6H8.1L4.6 13.2v-2.7H2.4z',
    logs: 'M3.2 3.6h9.6M3.2 7.6h9.6M3.2 11.6h6',
};

const groups = [
    { label: 'Overview', links: [{ label: 'Dashboard', icon: 'dashboard', route: 'dashboard', href: '/dashboard' }] },
    { label: 'Customers', links: [{ label: 'Customers', icon: 'customers', route: 'customers.index', href: '/customers' }] },
    { label: 'Billing', links: [{ label: 'Invoices', icon: 'invoices', route: 'billing.invoices.index', href: '/billing/invoices' }, { label: 'Payments', icon: 'payments', route: 'billing.payments.index', href: '/billing/payments' }] },
    { label: 'Network', links: [{ label: 'Routers', icon: 'routers', route: 'routers.index', href: '/routers' }, { label: 'Network accounts', icon: 'accounts', route: 'network.accounts.index', href: '/network/accounts' }, { label: 'Monitoring', icon: 'monitoring', route: 'monitoring.index', href: '/monitoring' }] },
    { label: 'Operations', links: [{ label: 'Outage incidents', icon: 'outages', route: 'monitoring.index', href: '/monitoring#outage-incidents', hash: '#outage-incidents' }, { label: 'Messages', icon: 'messages', route: 'messages.index', href: '/messages' }] },
    { label: 'System', links: [{ label: 'Operation logs', icon: 'logs', route: 'network.logs.index', href: '/network/logs' }] },
];

/* The topbar states where the operator is, in operations language. */
const titles = {
    dashboard: 'Command center',
    'customers.index': 'Customers', 'customers.create': 'New customer', 'customers.show': 'Customer 360', 'customers.edit': 'Edit customer',
    'billing.invoices.index': 'Invoices', 'billing.invoices.show': 'Invoice', 'billing.payments.index': 'Payments', 'billing.payment-requests.show': 'Payment request',
    'routers.index': 'Routers', 'routers.show': 'Router', 'routers.edit': 'Edit router', 'routers.create': 'New router',
    'network.accounts.index': 'Network accounts', 'network.logs.index': 'Operation logs',
    'monitoring.index': 'Monitoring console', 'monitoring.incidents.show': 'Outage incident', 'monitoring.history': 'Health history',
    'messages.index': 'Messages',
    'packages.index': 'Packages', 'packages.create': 'New package', 'packages.show': 'Package', 'packages.edit': 'Edit package',
};

const title = computed(() => titles[props.currentRoute] || 'CosmicLink workspace');
const section = computed(() => (groups.find((group) => group.links.some((link) => link.route === props.currentRoute))?.label || 'Operations').toLowerCase());
const active = (link) => {
    if (props.currentRoute !== link.route) return false;
    if (link.hash) return hash.value === link.hash;
    return !(link.route === 'monitoring.index' && hash.value === '#outage-incidents');
};
</script>


<template>
    <aside class="sidebar" :class="{ 'sidebar--open': mobileOpen }">
        <a class="brand" :href="href('/dashboard')" @click="close">
            <span class="brand__mark" aria-hidden="true"></span>
            <span><span class="brand__name">CosmicLink</span><span class="brand__sub">ISP operations, automated</span></span>
        </a>
        <nav class="sidebar__nav" aria-label="Primary navigation">
            <div v-for="group in groups" :key="group.label" class="nav-group">
                <p class="nav-group__label">{{ group.label }}</p>
                <a v-for="link in group.links" :key="link.label" class="nav-link" :class="{ 'nav-link--active': active(link) }" :href="href(link.href)" :aria-current="active(link) ? 'page' : undefined" @click="close">
                    <span class="nav-link__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path :d="icons[link.icon]"></path></svg></span>{{ link.label }}
                </a>
            </div>
        </nav>
        <div class="sidebar__footer">
            <div class="operator-card">
                <span class="avatar" aria-hidden="true">{{ initials }}</span>
                <span><span class="operator-card__name">{{ operator }}</span><span class="operator-card__tenant">{{ tenant }}</span></span>
            </div>
        </div>
    </aside>
    <div v-if="mobileOpen" class="sidebar-backdrop" @click="close"></div>
    <header class="topbar">
        <div class="topbar__pill">
            <div class="topbar__slot">
                <button class="menu-toggle" type="button" aria-label="Open navigation" :aria-expanded="mobileOpen ? 'true' : 'false'" @click="mobileOpen = true">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" aria-hidden="true"><path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11"></path></svg>
                </button>
                <div>
                    <p class="topbar__eyebrow">{{ section }}</p>
                    <p class="topbar__title">{{ title }}</p>
                </div>
            </div>
            <div class="topbar__actions">
                <span class="topbar__context">{{ tenant }}</span>
                <span v-if="simulation" class="topbar__mode" title="Simulation mode is active">SIMULATION_MODE: ACTIVE</span>
                <button class="theme-toggle" type="button" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" :title="theme === 'dark' ? 'Light mode' : 'Dark mode'" @click="toggleTheme">
                    <svg v-if="theme === 'dark'" width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="3.1"></circle><path d="M8 1.5v1.4M8 13.1v1.4M1.5 8h1.4M13.1 8h1.4M3.4 3.4l1 1M11.6 11.6l1 1M12.6 3.4l-1 1M4.4 11.6l-1 1"></path></svg>
                    <svg v-else width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true"><path d="M13.2 10.5A5.8 5.8 0 0 1 5.5 2.8 5.8 5.8 0 1 0 13.2 10.5Z"></path></svg>
                </button>
                <form class="logout-form" method="post" :action="href('/logout')">
                    <input type="hidden" name="_token" :value="csrfToken">
                    <button class="button button--quiet button--sm" type="submit">Sign out</button>
                </form>
            </div>
        </div>
    </header>
</template>
