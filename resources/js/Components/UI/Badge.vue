<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'gray' }, // gray | blue | green | yellow | red | purple | indigo
});

const classes = computed(
    () =>
        ({
            gray: 'bg-gray-100 text-gray-700 ring-gray-500/15',
            blue: 'bg-sky-50 text-sky-700 ring-sky-600/20',
            green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            yellow: 'bg-amber-50 text-amber-700 ring-amber-600/20',
            red: 'bg-rose-50 text-rose-700 ring-rose-600/20',
            purple: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20',
            indigo: 'bg-violet-50 text-violet-700 ring-violet-600/20',
        })[props.color] ?? 'bg-gray-100 text-gray-700 ring-gray-500/15',
);
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', classes]">
        <slot />
    </span>
</template>
