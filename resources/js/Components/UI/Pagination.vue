<script setup>
import { Link } from '@inertiajs/vue3';

// Accepts the `links` array from a Laravel paginator resource.
defineProps({
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap items-center gap-1" aria-label="Pagination">
        <template v-for="(l, i) in links" :key="i">
            <span
                v-if="!l.url"
                class="rounded-md px-3 py-1.5 text-sm text-gray-400"
                v-html="l.label"
            />
            <Link
                v-else
                :href="l.url"
                preserve-scroll
                :class="[
                    'rounded-md px-3 py-1.5 text-sm',
                    l.active ? 'bg-indigo-600 font-medium text-white' : 'text-gray-600 hover:bg-gray-100',
                ]"
                v-html="l.label"
            />
        </template>
    </nav>
</template>
