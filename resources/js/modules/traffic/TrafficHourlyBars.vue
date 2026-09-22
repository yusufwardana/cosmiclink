<script setup>
import { computed } from 'vue';
import { formatBytes } from './trafficFormatters.js';
import { barGeometry, seriesSummary } from './trafficCharts.js';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    label: { type: String, default: 'Hourly traffic' },
    width: { type: Number, default: 420 },
    height: { type: Number, default: 160 },
});

const ordered = computed(() => [...(props.rows ?? [])]);
const geometry = computed(() => barGeometry({ rows: ordered.value, width: props.width, height: props.height }));
const summary = computed(() => seriesSummary({ points: ordered.value.map((row) => ({ ...row, hour: row.hour })), label: props.label }));
</script>

<template>
    <svg
        class="traffic-chart"
        :viewBox="`0 0 ${props.width} ${props.height}`"
        role="img"
        :aria-label="summary"
        preserveAspectRatio="xMidYMid meet"
    >
        <g v-for="tick in geometry.ticks" :key="tick.value">
            <line :x1="geometry.plot.x" :x2="geometry.plot.x + geometry.plot.w" :y1="tick.y" :y2="tick.y" class="traffic-chart__grid" />
            <text :x="geometry.plot.x - 8" :y="tick.y + 4" text-anchor="end" class="traffic-chart__tick">{{ formatBytes(tick.value) }}</text>
        </g>
        <g v-for="(bar, index) in geometry.bars" :key="index">
            <rect :x="bar.x" :y="bar.y" :width="bar.width" :height="bar.height" rx="2" class="traffic-chart__bar">
                <title>{{ bar.label }} — {{ formatBytes(bar.value) }} total</title>
            </rect>
        </g>
    </svg>
</template>
