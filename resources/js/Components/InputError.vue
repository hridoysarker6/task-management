<script setup>
import { ExclamationCircleIcon } from '@heroicons/vue/20/solid';

defineProps({
    message: { type: String },
});
</script>

<template>
    <div v-show="message" role="alert">
        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-rose-600">
            <ExclamationCircleIcon class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ message }}</span>
        </p>
    </div>
</template>
