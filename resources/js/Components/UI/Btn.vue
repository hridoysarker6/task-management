<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | ghost
    size: { type: String, default: 'md' }, // sm | md | lg
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const classes = computed(() => [
    'inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg font-semibold transition-all duration-150 focus:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none',
    { sm: 'px-2.5 py-1.5 text-xs', md: 'px-4 py-2 text-sm', lg: 'px-5 py-2.5 text-sm' }[props.size],
    {
        primary: 'btn-primary focus-visible:ring-violet-500/30',
        secondary: 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:border-gray-400 hover:bg-gray-50 active:scale-[0.98] focus-visible:ring-violet-500/20',
        danger: 'bg-gradient-to-b from-red-500 to-red-600 text-white shadow-sm ring-1 ring-inset ring-red-700/30 hover:from-red-500 hover:to-red-700 active:scale-[0.98] focus-visible:ring-red-500/30',
        ghost: 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 active:scale-[0.98] focus-visible:ring-violet-500/20',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes"><slot /></Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <slot />
    </button>
</template>
