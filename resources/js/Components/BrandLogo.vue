<script setup>
// The Task Pro mark: a violet tile with a check, and the "Task Pro" wordmark. `inverse` is for dark or gradient backgrounds.
defineProps({
    size: { type: String, default: 'md' }, // sm | md | lg | xl
    markOnly: { type: Boolean, default: false },
    inverse: { type: Boolean, default: false },
});

const sizes = {
    sm: { tile: 'h-7 w-7 rounded-lg', icon: 'h-4 w-4', text: 'text-base' },
    md: { tile: 'h-9 w-9 rounded-xl', icon: 'h-5 w-5', text: 'text-xl' },
    lg: { tile: 'h-11 w-11 rounded-xl', icon: 'h-6 w-6', text: 'text-2xl' },
    xl: { tile: 'h-14 w-14 rounded-2xl', icon: 'h-8 w-8', text: 'text-3xl' },
};
</script>

<template>
    <span class="inline-flex items-center gap-2.5 select-none" aria-label="Task Pro">
        <span
            :class="[
                'inline-flex shrink-0 items-center justify-center bg-gradient-to-br from-violet-500 to-violet-700 shadow-glow ring-1 ring-inset ring-white/20',
                sizes[size].tile,
            ]"
        >
            <svg :class="[sizes[size].icon, 'text-white']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12.5l4.5 4.5L19 7" />
            </svg>
        </span>
        <span v-if="!markOnly" :class="['font-extrabold tracking-tight', sizes[size].text, inverse ? 'text-white' : 'text-gray-900']">
            Task<span :class="inverse ? 'text-violet-200' : 'text-gradient'"> Pro</span>
        </span>
    </span>
</template>
