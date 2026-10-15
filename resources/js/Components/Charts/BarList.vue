<script setup>
import { computed } from 'vue';

// Horizontal bars. items: [{ label, value, extra? }]; `extra` is shown in red on top (for example overdue).
const props = defineProps({
    items: { type: Array, required: true },
    unit: { type: String, default: '' },
});

const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)));
</script>

<template>
    <ul class="space-y-2" role="list">
        <li v-for="i in items" :key="i.label" class="text-sm">
            <div class="flex justify-between gap-2"><span class="truncate text-gray-800">{{ i.label }}</span><span class="shrink-0 text-gray-500">{{ i.value }}{{ unit }}<span v-if="i.extra" class="ml-1 text-red-600">({{ i.extra }} overdue)</span></span></div>
            <div class="mt-0.5 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-indigo-500" :style="{ width: (i.value / max) * 100 + '%' }" /></div>
        </li>
    </ul>
</template>
