<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
});
</script>

<template>
    <Link :href="href" class="mx-1.5 my-0.5 block rounded-lg px-3 py-2 text-start text-sm leading-5 text-gray-700 transition-colors hover:bg-violet-50 hover:text-violet-700 focus:bg-violet-50 focus:outline-none" style="width: calc(100% - 0.75rem)">
        <slot />
    </Link>
</template>
