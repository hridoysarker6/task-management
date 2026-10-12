<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'gray' }, // gray | blue | green | yellow | red | purple | indigo
});

const classes = computed(
    () =>
        ({
            gray: 'bg-gray-100 text-gray-700',
            blue: 'bg-blue-100 text-blue-700',
            green: 'bg-green-100 text-green-700',
            yellow: 'bg-yellow-100 text-yellow-800',
            red: 'bg-red-100 text-red-700',
            purple: 'bg-purple-100 text-purple-700',
            indigo: 'bg-indigo-100 text-indigo-700',
        })[props.color] ?? 'bg-gray-100 text-gray-700',
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', classes]">
        <slot />
    </span>
</template>
