<script setup>
defineProps({ label: { type: String, required: true } });
</script>
<template><span class="ui-status-badge" :class="`ui-status-badge--${label.toLowerCase().replaceAll(' ', '-')}`">{{ label }}</span></template>