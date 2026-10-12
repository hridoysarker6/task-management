<script setup>
defineProps({
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: null },
    error: { type: String, default: null },
});
const model = defineModel();
</script>

<template>
    <select
        v-model="model"
        :class="[
            'block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
            error ? 'border-red-400' : '',
        ]"
    >
        <option v-if="placeholder" :value="null" disabled>{{ placeholder }}</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
        <slot />
    </select>
</template>
