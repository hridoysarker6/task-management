<script setup>
// A grey pulsing placeholder shown while something loads. <Skeleton class="h-4 w-32" /> or lines="3" for a block of text.
defineProps({ lines: { type: Number, default: 1 } });
</script>

<template>
    <div class="animate-pulse space-y-2" aria-hidden="true">
        <div v-for="n in lines" :key="n" class="h-4 rounded bg-gray-200" :class="n === lines && lines > 1 ? 'w-2/3' : 'w-full'" />
    </div>
</template>
