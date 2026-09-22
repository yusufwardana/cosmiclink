<script setup>
import { computed } from 'vue';
import { chronological, formatBytes, formatHour } from './trafficFormatters.js';
import { lineGeometry, seriesSummary } from './trafficCharts.js';

const props = defineProps({
    points: { type: Array, default: () => [] },
    label: { type: String, default: 'Traffic' },
    width: { type: Number, default: 640 },
    height: { type: Number, default: 200 },
    showLabels: { type: Boolean, default: true },
});

const ordered = computed(() => chronological(props.points ?? []));
const geometry = computed(() => lineGeometry({ points: ordered.value, width: props.width, height: props.height }));
const summary = computed(() => seriesSummary({ points: ordered.value, label: props.label }));
const shortHour = (value) => formatHour(value);
const labelledPoints = computed(() => {
    const plotted = geometry.value.points;
    if (plotted.length <= 8) return plotted;
    const step = Math.ceil(plotted.length / 8);
    return plotted.filter((_, index) => index % step === 0);
});
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
        <path :d="geometry.downloadArea" class="traffic-chart__area" />
        <path :d="geometry.uploadPath" class="traffic-chart__series traffic-chart__series--upload" fill="none" />
        <path :d="geometry.downloadPath" class="traffic-chart__series traffic-chart__series--download" fill="none" />
        <g v-for="(point, index) in geometry.points" :key="index">
            <circle :cx="point.x" :cy="point.downloadY" r="2.5" class="traffic-chart__dot traffic-chart__dot--download">
                <title>{{ point.label }} — download {{ formatBytes(point.download_bytes) }}, upload {{ formatBytes(point.upload_bytes) }}</title>
            </circle>
        </g>
        <g v-if="showLabels && geometry.points.length">
            <text
                v-for="(point, index) in labelledPoints"
                :key="`label-${index}`"
                :x="point.x"
                :y="geometry.plot.baseline + 18"
                text-anchor="middle"
                class="traffic-chart__tick"
            >{{ shortHour(point.hour) }}</text>
        </g>
    </svg>
</template>