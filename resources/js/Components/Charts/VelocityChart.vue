<script setup>
import { computed } from 'vue';

// Committed (grey) against completed (indigo) points of recent sprints. sprints: [{ name, committed, completed }]
const props = defineProps({
    sprints: { type: Array, required: true },
    average: { type: Number, default: null },
    height: { type: Number, default: 150 },
});

const W = 360;
const max = computed(() => Math.max(1, ...props.sprints.flatMap((s) => [s.committed, s.completed])));
const slot = computed(() => (W - 30) / Math.max(1, props.sprints.length));
const h = (v) => (v / max.value) * (props.height - 34);
</script>

<template>
    <figure>
        <svg :viewBox="`0 0 ${W} ${height}`" class="w-full" role="img" :aria-label="'Velocity: ' + sprints.map((s) => `${s.name} ${s.completed} of ${s.committed}`).join(', ')">
            <g v-for="(s, i) in sprints" :key="s.name">
                <rect :x="30 + i * slot + slot * 0.12" :y="height - 20 - h(s.committed)" :width="slot * 0.36" :height="h(s.committed)" fill="#d1d5db" rx="2" />
                <rect :x="30 + i * slot + slot * 0.5" :y="height - 20 - h(s.completed)" :width="slot * 0.36" :height="h(s.completed)" fill="#7c3aed" rx="2" />
                <text :x="30 + i * slot + slot / 2" :y="height - 7" text-anchor="middle" class="fill-gray-500 text-[8px]">{{ s.name.length > 10 ? s.name.slice(0, 9) + '…' : s.name }}</text>
            </g>
            <text x="26" :y="height - 20" text-anchor="end" class="fill-gray-400 text-[8px]">0</text>
            <text x="26" y="14" text-anchor="end" class="fill-gray-400 text-[8px]">{{ max }}</text>
        </svg>
        <figcaption class="mt-1 flex flex-wrap gap-4 text-xs text-gray-500">
            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-gray-300" /> Committed</span>
            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-indigo-600" /> Completed</span>
            <span v-if="average !== null">Average {{ average }} points</span>
        </figcaption>
    </figure>
</template>
