<script setup>
/**
 * NetworkTopology.vue
 * Cytoscape.js topology canvas for the CosmicLink Network Operations Dashboard.
 *
 * Topology: Internet → Router → [Simple Queue group | PPPoE group]
 *           Simple Queue group (collapsed by default) → subscriber nodes
 *
 * Rules:
 * - Aggregate queue targets (/24, multi-IP) never appear as subscriber nodes.
 * - No continuous wobble after stabilisation.
 * - Drag/pan/zoom/fit/click/hover/search all supported.
 * - No RouterOS calls, no fake data, no mutations.
 */
import cytoscape from 'cytoscape';
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import {
    SUBSCRIBER_BATCH_SIZE,
    hasRecentTraffic,
    remainingSubscriberCount,
    subscriberSearchMatch,
    truncateTopologyLabel,
    visibleSubscriberNodes,
    radialTopologyPositions,
    hierarchicalTopologyPositions,
} from './networkTopologyUtils.js';

const props = defineProps({
    routers:         { type: Array, default: () => [] },
    subscriberNodes: { type: Array, default: () => [] },
    pppoeNodes:      { type: Array, default: () => [] },
    deviceNodes:    { type: Array, default: () => [] },
    accessModeGroups:{ type: Array, default: () => [] },
    interfaceTraffic:{ type: Array, default: () => [] },
    aggregateCount:  { type: Number, default: 0 },
    selectedNodeId:  { type: String, default: null },
    searchQuery:     { type: String, default: '' },
});

const emit = defineEmits(['node-selected', 'node-cleared']);

const canvasEl = ref(null);
let cy = null;

// Expand/collapse state per group node.
const expanded = ref({ 'group-static-ip': false, 'group-hotspot': false });
const expandedCustomers = ref(new Set());
const visibleLimit = ref(SUBSCRIBER_BATCH_SIZE);
const showAllSubscribers = ref(false);
const focusedSubscriberId = ref(null);
const hoveredNodeId = ref(null);
const hoverTooltip = ref(null);
const markerLayerEl = ref(null);
let animationFrame = 0;
let animationProgress = 0;
let documentVisibilityHandler = null;
let resizeObserver = null;
const flowMarkerEls = new Map();

// ── Colour helpers ─────────────────────────────────────────────────────────
const stateColour = (state) => {
    switch ((state ?? '').toLowerCase()) {
        case 'online':    return '#4de8a5';  // --cl-ok
        case 'degraded':  return '#e8c84d';  // --cl-brass
        case 'offline':   return '#e84d4d';  // --cl-danger
        default:          return '#5c7a8a';  // muted
    }
};

const managedColour = (state) => {
    if ((state ?? '') === 'MANAGED') return '#4de8a5';
    return '#3a8fa8';  // cyan — discovered
};

const graphPositions = (subscriberCount = 0) => {
    const rect = canvasEl.value?.getBoundingClientRect();
    const width = Math.max(900, rect?.width ?? 900);
    const height = Math.max(720, rect?.height ?? 720);
    const groups = props.accessModeGroups.map((group) => group.id);
    const customers = props.subscriberNodes.map((customer) => ({ id: customer.id, group: accessGroupId(customer.access_mode) }));
    const devices = props.deviceNodes.map((device) => ({ id: device.id, customer: props.subscriberNodes.find((customer) => customer.customer_connection_id === device.customer_connection_id)?.id }));
    return hierarchicalTopologyPositions(width, height, { groups, customers, devices });
};

const positionFor = (data, index = 0, positions = graphPositions()) => {
    if (data.type === 'router') return positions.router;
    if (positions[data.id]) return positions[data.id];
    if (data.type === 'subscriber') return positions[`subscriber-${index}`] ?? positions['subscriber-0'];
    if (data.type === 'device') return positions[`subscriber-${index}`] ?? positions['subscriber-0'];
    if (data.type === 'more' || data.type === 'show-all') return positions[data.id] ?? positions['subscriber-0'];
    return positions.router;
};

const nodeElement = (data, index = 0, positions = graphPositions()) => ({
    data: {
        ...data,
        displayLabel: data.displayLabel ?? [data.label, data.sub].filter(Boolean).join('\n'),
    },
    position: positionFor(data, index, positions),
});

const reducedMotion = () => typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const accessGroupId = (value) => `group-${String(value ?? '').toLowerCase().replace(/\s+/g, '_')}`;

const selectedPathIds = () => {
    const activeId = focusedSubscriberId.value || (hoveredNodeId.value && cy?.getElementById(hoveredNodeId.value)?.data('type') === 'subscriber' ? hoveredNodeId.value : null);
    if (!activeId) return [];
    const subscriber = cy?.getElementById(activeId);
    if (!subscriber?.length) return [];
    return ['internet', `router-${props.routers[0]?.id}`, accessGroupId(cy?.getElementById(activeId)?.data('kind')), activeId];
};

const updatePathState = () => {
    if (!cy) return;
    const path = new Set(selectedPathIds());
    const active = focusedSubscriberId.value || hoveredNodeId.value;
    cy.nodes().removeClass('dimmed');
    cy.edges().removeClass('path-muted path-active flowing');
    if (!active) return;

    cy.nodes().forEach((node) => {
        if (!path.has(node.id()) && node.data('type') !== 'internet' && node.data('type') !== 'router') node.addClass('dimmed');
    });
    cy.edges().forEach((edge) => {
        const source = edge.data('source');
        const target = edge.data('target');
        if (path.has(source) && path.has(target)) {
            edge.addClass('path-active');
            if (edge.data('traffic') === true) edge.addClass('flowing');
        }
        else edge.addClass('path-muted');
    });
};

const stopTrafficAnimation = () => {
    if (animationFrame) cancelAnimationFrame(animationFrame);
    animationFrame = 0;
};

const clearFlowMarkers = () => {
    flowMarkerEls.forEach((marker) => marker.remove());
    flowMarkerEls.clear();
};

const eligibleFlowEdges = () => {
    if (!cy || reducedMotion() || document.hidden) return [];
    return cy.edges().filter((edge) => edge.data('traffic') === true && (edge.data('type') !== 'subscriber' || edge.hasClass('flowing')));
};

const syncFlowMarkers = () => {
    if (!markerLayerEl.value) return;
    const eligible = eligibleFlowEdges();
    const activeIds = new Set(eligible.map((edge) => edge.id()));
    flowMarkerEls.forEach((marker, id) => {
        if (!activeIds.has(id)) {
            marker.remove();
            flowMarkerEls.delete(id);
        }
    });
    eligible.forEach((edge) => {
        if (!flowMarkerEls.has(edge.id())) {
            const marker = document.createElement('span');
            marker.className = 'topo-flow-marker';
            marker.setAttribute('aria-hidden', 'true');
            markerLayerEl.value.appendChild(marker);
            flowMarkerEls.set(edge.id(), marker);
        }
    });
};

const updateFlowMarkers = () => {
    const eligible = eligibleFlowEdges();
    syncFlowMarkers();
    eligible.forEach((edge, edgeIndex) => {
        const source = edge.source().renderedPosition();
        const target = edge.target().renderedPosition();
        const progress = (animationProgress + edgeIndex * 0.18) % 1;
        const marker = flowMarkerEls.get(edge.id());
        if (!marker || !source || !target) return;
        marker.style.transform = `translate(${source.x + (target.x - source.x) * progress}px, ${source.y + (target.y - source.y) * progress}px)`;
    });
};

const animateTraffic = () => {
    stopTrafficAnimation();
    clearFlowMarkers();
    if (!cy || reducedMotion() || document.hidden) return;
    syncFlowMarkers();
    if (!flowMarkerEls.size) return;

    const tick = () => {
        if (!cy || reducedMotion() || document.hidden) {
            animationFrame = 0;
            clearFlowMarkers();
            return;
        }
        animationProgress = (animationProgress + 0.012) % 1;
        updateFlowMarkers();
        animationFrame = requestAnimationFrame(tick);
    };
    animationFrame = requestAnimationFrame(tick);
};

const refreshAnimation = () => {
    updatePathState();
    animateTraffic();
};

// ── Build Cytoscape elements ───────────────────────────────────────────────
const buildElements = () => {
    const elements = [];
    const positions = graphPositions(showAllSubscribers.value ? props.subscriberNodes.length : visibleLimit.value);

    // Internet pseudo-node.
    elements.push(nodeElement({ id: 'internet', label: 'Internet', sub: 'Upstream', type: 'internet', color: '#3a8fa8' }, 0, positions));

    // Router nodes
    for (const r of props.routers) {
        const color = stateColour(r.monitoring_state);
        elements.push(nodeElement({
                id:     `router-${r.id}`,
                label:  r.name,
                sub:    (r.monitoring_state ?? 'unknown').toUpperCase(),
                type:   'router',
                color,
                raw:    r,
            }, 0, positions));
        elements.push({ data: { id: `e-internet-router-${r.id}`, source: 'internet', target: `router-${r.id}`, type: 'uplink', traffic: hasRecentTraffic(props.interfaceTraffic) } });
    }

    const routerId = props.routers[0]?.id;
    if (!routerId) return elements;

    const routerNodeId = `router-${routerId}`;

    const groupNodes = props.accessModeGroups.length ? props.accessModeGroups : [
        { id: 'group-static-ip', label: 'STATIC IP', count: props.subscriberNodes.filter((node) => node.access_mode === 'STATIC IP').length },
        { id: 'group-hotspot', label: 'HOTSPOT', count: props.subscriberNodes.filter((node) => node.access_mode === 'HOTSPOT').length },
    ];
    groupNodes.forEach((group) => {
        const groupId = group.id || accessGroupId(group.label);
        elements.push(nodeElement({ id: groupId, label: group.label, sub: `${group.count ?? 0} customers`, type: 'group', kind: group.label, expandable: (group.count ?? 0) > 0, color: group.label === 'HOTSPOT' ? '#5a6aa8' : '#3a8fa8' }, 0, positions));
        elements.push({ data: { id: `e-router-${groupId}`, source: routerNodeId, target: groupId, type: 'mode', traffic: hasRecentTraffic(props.interfaceTraffic) } });
    });

    for (const group of groupNodes) {
        const groupId = group.id || accessGroupId(group.label);
        if (!expanded.value[groupId]) continue;
        const groupMode = group.label;
        const groupCustomers = props.subscriberNodes.filter((node) => node.access_mode === groupMode);
        const visible = showAllSubscribers.value ? groupCustomers : visibleSubscriberNodes(groupCustomers, visibleLimit.value, focusedSubscriberId.value);
        visible.forEach((customer, index) => {
            const devices = props.deviceNodes.filter((device) => device.customer_connection_id === customer.customer_connection_id);
            const customerExpanded = expandedCustomers.value.has(customer.id);
            elements.push(nodeElement({ id: customer.id, label: truncateTopologyLabel(customer.name), fullLabel: customer.name, sub: truncateTopologyLabel(customer.identity ?? '', 18), fullTarget: customer.identity ?? '', type: 'subscriber', kind: groupMode, color: managedColour(customer.management_state), raw: customer, device_ips: devices.map((device) => device.ip_address).filter(Boolean), device_macs: devices.map((device) => device.mac_address).filter(Boolean) }, index, positions));
            elements.push({ data: { id: `e-${groupId}-${customer.id}`, source: groupId, target: customer.id, type: 'subscriber' } });
            if (customerExpanded) devices.forEach((device, deviceIndex) => {
                elements.push(nodeElement({ id: device.id, label: truncateTopologyLabel(device.name), sub: truncateTopologyLabel(device.ip_address ?? device.mac_address ?? '', 18), type: 'device', color: '#7b9aaa', raw: device }, deviceIndex, positions));
                elements.push({ data: { id: `e-${customer.id}-${device.id}`, source: customer.id, target: device.id, type: 'device' } });
            });
        });
        const remaining = remainingSubscriberCount(groupCustomers, visible.length);
        if (!showAllSubscribers.value && remaining > 0) {
            elements.push(nodeElement({ id: `${groupId}-more`, label: `+ ${remaining} more`, sub: 'Load batch', type: 'more', color: '#5c7a8a' }, visible.length, positions));
            elements.push({ data: { id: `e-${groupId}-more`, source: groupId, target: `${groupId}-more`, type: 'subscriber' } });
        }
    }

    return elements;
};

// ── Cytoscape style ─────────────────────────────────────────────────────────
const cyStyle = [
    { selector: 'node', style: {
        'background-color': 'data(color)', 'border-width': 2, 'border-color': 'data(color)', 'border-opacity': 0.55,
            'label': 'data(displayLabel)', 'color': '#e8eff4', 'font-family': 'JetBrains Mono, monospace', 'font-size': 11,
        'text-valign': 'bottom', 'text-halign': 'center', 'text-margin-y': 6,
            'text-wrap': 'wrap', 'text-max-width': 96,
        'text-background-color': '#0c1f28', 'text-background-opacity': 0.75,
        'text-background-padding': '3px', 'text-background-shape': 'roundrectangle',
        'width': 42, 'height': 42, 'shape': 'ellipse',
    }},
    { selector: 'node[type="internet"]',    style: { 'background-color': '#1a3d50', 'border-color': '#3a8fa8', 'border-width': 2, 'width': 58, 'height': 38, 'shape': 'ellipse', 'color': '#3a8fa8', 'font-size': 10, 'font-weight': 600 } },
    { selector: 'node[type="router"]',      style: { 'width': 82, 'height': 82, 'shape': 'ellipse', 'font-size': 11, 'font-weight': 700, 'border-width': 3 } },
    { selector: 'node[type="group"]',       style: { 'width': 88, 'height': 58, 'shape': 'roundrectangle', 'font-size': 10, 'font-weight': 600 } },
    { selector: 'node[type="subscriber"]',  style: { 'width': 112, 'height': 38, 'shape': 'roundrectangle', 'font-size': 9 } },
    { selector: 'node[type="device"]',      style: { 'width': 92, 'height': 30, 'shape': 'roundrectangle', 'font-size': 8 } },
    { selector: 'node[type="more"], node[type="show-all"]', style: { 'width': 112, 'height': 30, 'shape': 'roundrectangle', 'font-size': 8, 'background-color': '#172b35', 'border-style': 'dashed' } },
    { selector: 'node:selected', style: { 'border-width': 3, 'border-color': '#4de8a5', 'border-opacity': 1 } },
    { selector: 'node.highlighted', style: { 'border-width': 3, 'border-color': '#4de8a5', 'border-opacity': 1 } },
    { selector: 'node.dimmed', style: { 'opacity': 0.25 } },
    { selector: 'edge', style: { 'width': 1.5, 'line-color': '#1e4055', 'curve-style': 'bezier', 'opacity': 0.75 } },
    { selector: 'edge[type="uplink"]',      style: { 'line-color': '#3a8fa8', 'width': 2.5, 'opacity': 0.85 } },
    { selector: 'edge[type="mode"]',        style: { 'line-color': '#2d5f78', 'width': 2,   'opacity': 0.8  } },
    { selector: 'edge[type="subscriber"]',  style: { 'line-color': '#1e3d50', 'width': 1.2, 'opacity': 0.55, 'line-style': 'dashed', 'line-dash-pattern': [4, 3] } },
    { selector: 'edge.traffic-active', style: { 'line-color': '#3a8fa8', 'opacity': 0.9, 'line-style': 'dashed', 'line-dash-pattern': [6, 5] } },
    { selector: 'edge.path-active', style: { 'line-color': '#4de8a5', 'opacity': 1, 'width': 2.5 } },
    { selector: 'edge.flowing', style: { 'line-style': 'dashed', 'line-dash-pattern': [6, 5] } },
    { selector: 'edge.path-muted', style: { 'opacity': 0.18 } },
];

const fitOptions = { padding: 56 };

const initCy = () => {
    if (!canvasEl.value) return;
    if (cy) { cy.destroy(); cy = null; }
    const els = buildElements();
    cy = cytoscape({ container: canvasEl.value, elements: els, style: cyStyle,
        minZoom: 0.15, maxZoom: 4 });
    cy.fit(undefined, fitOptions.padding);
    cy.edges().filter((edge) => edge.data('traffic') === true).addClass('traffic-active');
    cy.on('tap', 'node', (evt) => {
        const n = evt.target;
        if (n.data('type') === 'group' && n.data('expandable')) {
            if (expanded.value[n.id()]) {
                expanded.value[n.id()] = false;
                visibleLimit.value = SUBSCRIBER_BATCH_SIZE;
                showAllSubscribers.value = false;
                focusedSubscriberId.value = null;
            } else {
                expanded.value[n.id()] = !expanded.value[n.id()];
            }
            rebuildGraph(); return;
        }
        if (n.data('type') === 'more') {
            visibleLimit.value += SUBSCRIBER_BATCH_SIZE;
            rebuildGraph(); return;
        }
        if (n.data('type') === 'show-all') {
            showAllSubscribers.value = true;
            rebuildGraph(); return;
        }
        if (n.data('type') === 'subscriber') {
            focusedSubscriberId.value = n.id();
            const next = new Set(expandedCustomers.value);
            if (next.has(n.id())) next.delete(n.id()); else next.add(n.id());
            expandedCustomers.value = next;
            rebuildGraph();
        } else {
            focusedSubscriberId.value = null;
        }
        emit('node-selected', { id: n.id(), type: n.data('type'), raw: n.data('raw') });
        refreshAnimation();
    });
    cy.on('tap', (evt) => { if (evt.target === cy) emit('node-cleared'); });
    cy.on('mouseover', 'node', (evt) => {
        const node = evt.target;
        hoveredNodeId.value = node.id();
        if (node.data('fullLabel') || node.data('fullTarget')) {
            const position = node.renderedPosition();
            hoverTooltip.value = {
                label: node.data('fullLabel') || node.data('label'),
                target: node.data('fullTarget') || node.data('sub'),
                x: position.x + 14,
                y: position.y + 14,
            };
        }
        node.addClass('highlighted');
        refreshAnimation();
    });
    cy.on('mouseout', 'node', (evt) => {
        hoveredNodeId.value = null;
        hoverTooltip.value = null;
        evt.target.removeClass('highlighted');
        refreshAnimation();
    });
    documentVisibilityHandler = () => refreshAnimation();
    document.addEventListener('visibilitychange', documentVisibilityHandler);
    resizeObserver = new ResizeObserver(() => {
        if (!cy) return;
        cy.resize();
        cy.fit(undefined, fitOptions.padding);
    });
    resizeObserver.observe(canvasEl.value);
    refreshAnimation();
};

const rebuildGraph = () => {
    if (!cy) return;
    const els = buildElements();
    cy.elements().remove(); cy.add(els); cy.style(cyStyle);
    cy.resize();
    cy.fit(undefined, fitOptions.padding);
    refreshAnimation();
};

const fitGraph = () => cy?.fit(undefined, 40);
const zoomIn   = () => cy?.zoom({ level: cy.zoom() * 1.25, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
const zoomOut  = () => cy?.zoom({ level: cy.zoom() * 0.8,  renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
const relayout = () => {
    if (!cy) return;
    rebuildGraph();
};

watch(() => props.searchQuery, (q) => {
    if (!cy) return;
    cy.nodes().removeClass('highlighted');
    if (!q) { focusedSubscriberId.value = null; expandedCustomers.value = new Set(); emit('node-cleared'); return; }
    const match = props.subscriberNodes.find((node) => subscriberSearchMatch(node, q));
    if (match) {
        focusedSubscriberId.value = match.id;
        const term = q.trim().toLowerCase();
        if ([...(match.device_ips ?? []), ...(match.device_macs ?? [])].some((value) => String(value).toLowerCase().includes(term))) {
            const next = new Set(expandedCustomers.value);
            next.add(match.id);
            expandedCustomers.value = next;
        }
    }
    if (match) {
        expanded.value[accessGroupId(match.access_mode)] = true;
        rebuildGraph();
    }
    const resolveMatch = () => {
        const term = q.trim().toLowerCase();
        const hits = cy.nodes().filter((n) => {
            const raw = n.data('raw') || {};
            return [n.data('label'), n.data('sub'), raw.name, raw.code, raw.identity, raw.ip_address, raw.mac_address]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(term));
        });
        if (!hits.length) { focusedSubscriberId.value = null; expandedCustomers.value = new Set(); emit('node-cleared'); return; }
        const hit = hits.first();
        if (hit.data('type') === 'subscriber') {
            const next = new Set(expandedCustomers.value);
            next.add(hit.id());
            expandedCustomers.value = next;
            focusedSubscriberId.value = hit.id();
            rebuildGraph();
        }
        hits.addClass('highlighted');
        cy.nodes().unselect();
        hits.first().select();
        cy.animate({ fit: { eles: hits.first(), padding: 120 } }, { duration: 420 });
        emit('node-selected', { id: hits.first().id(), type: hits.first().data('type'), raw: hits.first().data('raw') });
        refreshAnimation();
    };
    if (match && expanded.value[accessGroupId(match.access_mode)]) requestAnimationFrame(resolveMatch);
    else resolveMatch();
});

watch(() => props.selectedNodeId, (id) => {
    if (!cy || !id) return;
    const n = cy.getElementById(id);
    if (!n.length) return;
    focusedSubscriberId.value = n.data('type') === 'subscriber' ? id : null;
    cy.nodes().unselect(); n.select();
    cy.animate({ fit: { eles: n, padding: 120 } }, { duration: 350 });
    refreshAnimation();
});

watch([() => props.routers, () => props.subscriberNodes, () => props.pppoeNodes], () => { if (cy) rebuildGraph(); }, { deep: true });

onMounted(initCy);
onBeforeUnmount(() => {
    stopTrafficAnimation();
    clearFlowMarkers();
    resizeObserver?.disconnect();
    resizeObserver = null;
    if (documentVisibilityHandler) document.removeEventListener('visibilitychange', documentVisibilityHandler);
    if (cy) { cy.destroy(); cy = null; }
});

defineExpose({ fitGraph, relayout,
    expandGroup: (id) => {
        if (!expanded.value[id]) {
            expanded.value[id] = true;
            rebuildGraph();
        }
    },
    focusSubscriber: (id) => {
        focusedSubscriberId.value = id;
        expanded.value[`group-${String((cy?.getElementById(id)?.data('kind') || '')).toLowerCase().replace(/\s+/g, '-')}`] = true;
        rebuildGraph();
        requestAnimationFrame(() => {
            const n = cy?.getElementById(id);
            if (!n?.length) return;
            cy.nodes().unselect(); n.select();
            cy.animate({ fit: { eles: n, padding: 120 } }, { duration: 350 });
            refreshAnimation();
        });
    },
    focusNode: (id) => {
        if (!cy) return;
        const n = cy.getElementById(id);
        if (n.length) { cy.nodes().unselect(); n.select(); cy.animate({ fit: { eles: n, padding: 120 } }, { duration: 350 }); }
    },
});
</script>

<template>
    <div class="topo-wrap">
        <div class="topo-controls">
            <button class="topo-btn" title="Zoom in"  @click="zoomIn">+</button>
            <button class="topo-btn" title="Zoom out" @click="zoomOut">−</button>
            <button class="topo-btn" title="Fit all"  @click="fitGraph">⊞</button>
            <button class="topo-btn" title="Re-layout" @click="relayout">⟳</button>
        </div>
        <div ref="canvasEl" class="topo-canvas" aria-label="Network topology map"></div>
        <div ref="markerLayerEl" class="topo-marker-layer" aria-hidden="true"></div>
        <div v-if="hoverTooltip" class="topo-tooltip" :style="{ left: `${hoverTooltip.x}px`, top: `${hoverTooltip.y}px` }">
            <strong>{{ hoverTooltip.label }}</strong>
            <span v-if="hoverTooltip.target">{{ hoverTooltip.target }}</span>
        </div>
        <p class="topo-hint">Click node to select · Drag to pan · Scroll to zoom · Click group to expand</p>
    </div>
</template>

