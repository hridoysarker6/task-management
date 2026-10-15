<script setup>
defineProps({
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: null },
    error: { type: String, default: null },
});
const model = defineModel();
</script>

<template>
    <select v-model="model" :class="['block w-full text-sm', error ? '!border-rose-400 !ring-rose-500/10' : '']">
        <option v-if="placeholder" :value="null" disabled>{{ placeholder }}</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
        <slot />
    </select>
</template>
