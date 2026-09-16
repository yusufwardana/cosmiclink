<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ baseUrl: String, csrfToken: String, currentRoute: String, tenant: String, operator: String, simulation: Boolean });
const mobileOpen = ref(false);
const initials = computed(() => (props.operator || 'OP').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase());
const groups = [
    { label: 'Overview', links: [{ label: 'Dashboard', icon: '⌂', route: 'dashboard', href: '/dashboard' }] },
    { label: 'Customers', links: [{ label: 'Customers', icon: '◎', route: 'customers.index', href: '/customers' }] },
    { label: 'Billing', links: [{ label: 'Invoices', icon: '▤', route: 'billing.invoices.index', href: '/billing/invoices' }, { label: 'Payments', icon: '↗', route: 'billing.payments.index', href: '/billing/payments' }] },
    { label: 'Network', links: [{ label: 'Routers', icon: '◈', route: 'routers.index', href: '/routers' }, { label: 'Network Accounts', icon: '⌁', route: 'network.accounts.index', href: '/network/accounts' }, { label: 'Monitoring', icon: '◌', route: 'monitoring.index', href: '/monitoring' }] },
    { label: 'Operations', links: [{ label: 'Outage Incidents', icon: '!', route: 'monitoring.index', href: '/monitoring#outage-incidents' }, { label: 'Messages', icon: '✉', route: 'messages.index', href: '/messages' }] },
    { label: 'System', links: [{ label: 'Operation Logs', icon: '≡', route: 'network.logs.index', href: '/network/logs' }] },
];
const active = (link) => props.currentRoute === link.route || (link.label === 'Outage Incidents' && props.currentRoute === 'monitoring.index');
const close = () => { mobileOpen.value = false; };
const href = (path) => `${props.baseUrl || ''}${path}`;
</script>

<template>
    <aside class="sidebar" :class="{ 'sidebar--open': mobileOpen }">
        <a class="brand" :href="href('/dashboard')" @click="close"><span class="brand__mark">✦</span><span><span class="brand__name">CosmicLink</span><span class="brand__sub">ISP Operations, Automated.</span></span></a>
        <nav class="sidebar__nav" aria-label="Primary navigation"><div v-for="group in groups" :key="group.label" class="nav-group"><div class="nav-group__label">{{ group.label }}</div><a v-for="link in group.links" :key="link.label" class="nav-link" :class="{ 'nav-link--active': active(link) }" :href="href(link.href)" @click="close"><span class="nav-link__icon">{{ link.icon }}</span>{{ link.label }}</a></div></nav>
        <div class="sidebar__footer"><div class="operator-card"><span class="avatar">{{ initials }}</span><span><span class="operator-card__name">{{ operator }}</span><span class="operator-card__tenant">{{ tenant }}</span></span></div><span v-if="simulation" class="simulation-chip">Simulation mode</span></div>
    </aside>
    <div v-if="mobileOpen" class="sidebar-backdrop" @click="close"></div>
    <header class="topbar"><div style="display:flex;align-items:center"><button class="menu-toggle" aria-label="Open navigation" @click="mobileOpen = true">☰</button><div><p class="topbar__eyebrow">Operations console</p><p class="topbar__title">{{ currentRoute === 'dashboard' ? 'Command center' : 'CosmicLink workspace' }}</p></div></div><div class="topbar__actions"><span v-if="simulation" class="topbar__mode">Simulation mode</span><form class="logout-form" method="post" :action="href('/logout')"><input type="hidden" name="_token" :value="csrfToken"><button class="button--quiet" type="submit">Sign out</button></form></div></header>
</template>