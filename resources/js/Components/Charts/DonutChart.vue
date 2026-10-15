<script setup>
import { computed } from 'vue';

// A ring split by share. items: [{ label, count, color }]. The total shows in the middle.
const props = defineProps({
    items: { type: Array, required: true },
    size: { type: Number, default: 140 },
    centerLabel: { type: String, default: 'tasks' },
});

const R = 40;
const C = 2 * Math.PI * R;
const total = computed(() => props.items.reduce((n, i) => n + i.count, 0));
const slices = computed(() => {
    let offset = 0;
    return props.items.map((i) => {
        const length = total.value ? (i.count / total.value) * C : 0;
        const slice = { ...i, dash: `${length} ${C - length}`, offset: -offset };
        offset += length;
        return slice;
    });
});
</script>

<template>
    <figure class="flex items-center gap-4">
        <svg :width="size" :height="size" viewBox="0 0 100 100" role="img" :aria-label="items.map((i) => `${i.label}: ${i.count}`).join(', ')">
            <circle cx="50" cy="50" :r="R" fill="none" stroke="#f3f4f6" stroke-width="14" />
            <circle v-for="s in slices" :key="s.label" cx="50" cy="50" :r="R" fill="none" :stroke="s.color" stroke-width="14" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" transform="rotate(-90 50 50)" />
            <text x="50" y="48" text-anchor="middle" class="fill-gray-900 text-[16px] font-semibold">{{ total }}</text>
            <text x="50" y="61" text-anchor="middle" class="fill-gray-400 text-[7px]">{{ centerLabel }}</text>
        </svg>
        <figcaption>
            <ul class="space-y-1 text-sm">
                <li v-for="i in items" :key="i.label" class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: i.color }" /> {{ i.label }} <span class="text-gray-400">{{ i.count }}</span></li>
            </ul>
        </figcaption>
    </figure>
</template>
