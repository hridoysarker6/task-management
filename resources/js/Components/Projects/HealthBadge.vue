<script setup>
import Badge from '@/Components/UI/Badge.vue';

// On track / at risk / delayed, with the reasons on hover.
defineProps({ health: { type: Object, required: true } });
const color = { on_track: 'green', at_risk: 'yellow', delayed: 'red' };
</script>

<template>
    <Badge :color="color[health.status]" :title="health.reasons.join(' ')">{{ health.label }}</Badge>
</template>
