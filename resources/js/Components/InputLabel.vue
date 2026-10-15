<script setup>
defineProps({
    value: { type: String },
});
</script>

<template>
    <label class="mb-1.5 block text-sm font-medium text-gray-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
