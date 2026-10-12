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

const palette = ['bg-indigo-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500'];
const color = computed(() => {
    let h = 0;
    for (const c of props.name) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return props.former ? 'bg-gray-400' : palette[h % palette.length];
});
const sizes = { sm: 'h-6 w-6 text-[10px]', md: 'h-8 w-8 text-xs', lg: 'h-12 w-12 text-base' };
</script>

<template>
    <img v-if="src" :src="src" :alt="name" :title="name" :class="['rounded-full object-cover', sizes[size]]" />
    <span
        v-else
        :title="name"
        :class="['inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white', sizes[size], color]"
    >
        {{ initials }}
    </span>
</template>
