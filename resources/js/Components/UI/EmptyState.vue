<script setup>
import { InboxIcon } from '@heroicons/vue/24/outline';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="flex animate-fade-in flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white/60 px-6 py-14 text-center">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 ring-1 ring-inset ring-violet-100">
            <slot name="icon"><InboxIcon class="h-6 w-6" /></slot>
        </div>
        <h3 class="text-sm font-semibold text-gray-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-gray-500">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
