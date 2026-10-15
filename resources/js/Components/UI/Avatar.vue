<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '?' },
    src: { type: String, default: null },
    size: { type: String, default: 'md' }, // sm | md | lg
    former: { type: Boolean, default: false },
});

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0].toUpperCase())
        .join('') || '?',
);

// A soft gradient per person, stable for the same name.
const palette = [
    'from-violet-500 to-violet-700',
    'from-emerald-400 to-emerald-600',
    'from-amber-400 to-orange-500',
    'from-rose-400 to-rose-600',
    'from-sky-400 to-blue-600',
    'from-fuchsia-400 to-fuchsia-600',
    'from-teal-400 to-teal-600',
];
const color = computed(() => {
    let h = 0;
    for (const c of props.name) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return props.former ? 'from-gray-300 to-gray-400' : palette[h % palette.length];
});
const sizes = { sm: 'h-6 w-6 text-[10px]', md: 'h-8 w-8 text-xs', lg: 'h-12 w-12 text-base' };
</script>

<template>
    <img v-if="src" :src="src" :alt="name" :title="name" :class="['rounded-full object-cover ring-2 ring-white', sizes[size]]" />
    <span
        v-else
        :title="name"
        :class="['inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br font-semibold text-white ring-2 ring-white', sizes[size], color]"
    >
        {{ initials }}
    </span>
</template>
