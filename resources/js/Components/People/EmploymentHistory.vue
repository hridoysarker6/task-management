<script setup>
import Badge from '@/Components/UI/Badge.vue';

// Join / exit history, newest first. A rehire adds a new period.
defineProps({
    periods: { type: Array, required: true },
});

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null);
const exitLabel = { resigned: 'Resigned', terminated: 'Terminated', contract_ended: 'Contract ended' };
</script>

<template>
    <p v-if="!periods.length" class="text-sm text-gray-500">No employment history yet.</p>
    <ol v-else class="space-y-4">
        <li v-for="(p, i) in periods" :key="p.id" class="border-l-2 border-gray-200 pl-4">
            <div class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                {{ fmt(p.joined_at) }} &rarr; {{ p.left_at ? fmt(p.left_at) : 'present' }}
                <Badge v-if="!p.left_at" color="green">Current</Badge>
                <Badge v-else-if="p.exit_type" color="gray">{{ exitLabel[p.exit_type] ?? p.exit_type }}</Badge>
                <span v-if="periods.length > 1" class="text-xs font-normal text-gray-400">Period {{ periods.length - i }}</span>
            </div>
            <div class="mt-0.5 text-sm text-gray-600">
                {{ p.role ?? '—' }}<template v-if="p.designation"> ({{ p.designation }})</template>
                <template v-if="p.department"> &middot; {{ p.department }}</template>
                <template v-if="p.manager"> &middot; reports to {{ p.manager }}</template>
            </div>
            <p v-if="p.exit_reason" class="mt-1 text-sm text-gray-500">{{ p.exit_reason }}</p>
        </li>
    </ol>
</template>
