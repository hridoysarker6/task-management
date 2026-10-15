<script setup>
import { computed } from 'vue';

// Points left each day (solid) against the line that would finish on time (dashed). days: [{ date, ideal, actual|null }]
const props = defineProps({
    days: { type: Array, required: true },
    committed: { type: Number, default: 0 },
    height: { type: Number, default: 180 },
});

const W = 520;
const PAD = { l: 34, r: 10, t: 10, b: 22 };
const max = computed(() => Math.max(1, props.committed, ...props.days.map((d) => d.actual ?? 0), ...props.days.map((d) => d.ideal)));
const x = (i) => PAD.l + (props.days.length > 1 ? (i / (props.days.length - 1)) * (W - PAD.l - PAD.r) : 0);
const y = (v) => PAD.t + (1 - v / max.value) * (props.height - PAD.t - PAD.b);
const line = (key) => props.days.map((d, i) => (d[key] === null ? null : `${x(i)},${y(d[key])}`)).filter(Boolean).join(' ');
const ticks = computed(() => [0, 0.5, 1].map((f) => Math.round(max.value * f * 10) / 10));
const label = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
</script>

<template>
    <figure>
        <svg :viewBox="`0 0 ${W} ${height}`" class="w-full" role="img" aria-label="Burndown: points remaining per day against the ideal line">
            <g v-for="t in ticks" :key="t">
                <line :x1="PAD.l" :x2="W - PAD.r" :y1="y(t)" :y2="y(t)" stroke="#e5e7eb" />
                <text :x="PAD.l - 6" :y="y(t) + 3" text-anchor="end" class="fill-gray-400 text-[9px]">{{ t }}</text>
            </g>
            <polyline :points="line('ideal')" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="4 3" />
            <polyline :points="line('actual')" fill="none" stroke="#4f46e5" stroke-width="2.5" />
            <circle v-for="(d, i) in days.filter((d) => d.actual !== null).slice(-1)" :key="d.date" :cx="x(days.indexOf(d))" :cy="y(d.actual)" r="3.5" fill="#4f46e5" />
            <text :x="PAD.l" :y="height - 6" class="fill-gray-400 text-[9px]">{{ label(days[0].date) }}</text>
            <text :x="W - PAD.r" :y="height - 6" text-anchor="end" class="fill-gray-400 text-[9px]">{{ label(days[days.length - 1].date) }}</text>
        </svg>
        <figcaption class="mt-1 flex gap-4 text-xs text-gray-500">
            <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-indigo-600" /> Remaining</span>
            <span class="flex items-center gap-1"><span class="inline-block h-0 w-4 border-t border-dashed border-gray-400" /> Ideal</span>
        </figcaption>
    </figure>
</template>
