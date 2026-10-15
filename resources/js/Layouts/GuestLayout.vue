<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import { CheckCircleIcon } from '@heroicons/vue/24/solid';
import { Link } from '@inertiajs/vue3';

defineProps({
    wide: { type: Boolean, default: false }, // roomier card for long forms
});

const points = ['Boards, backlogs and sprints in one place', 'Every company keeps its own people and data', 'Time tracking, reports and approvals built in'];
</script>

<template>
    <div class="grid min-h-screen bg-white lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <!-- Brand panel -->
        <aside class="relative hidden overflow-hidden bg-gradient-to-br from-violet-600 via-violet-700 to-indigo-900 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-fuchsia-500/30 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-sky-400/20 blur-3xl" />

            <Link href="/" class="relative inline-flex w-fit" aria-label="Task Pro home"><BrandLogo inverse size="lg" /></Link>

            <div class="relative">
                <h2 class="max-w-md text-4xl font-extrabold leading-tight tracking-tight">Plan the work. Ship it together.</h2>
                <p class="mt-4 max-w-md text-base text-violet-100">Task Pro keeps your team's projects, sprints and hours in one calm, organised workspace.</p>
                <ul class="mt-8 space-y-3">
                    <li v-for="p in points" :key="p" class="flex items-start gap-3 text-sm text-violet-50">
                        <CheckCircleIcon class="mt-0.5 h-5 w-5 shrink-0 text-violet-200" />{{ p }}
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-violet-200">&copy; {{ new Date().getFullYear() }} Task Pro</p>
        </aside>

        <!-- Form -->
        <main class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-10 sm:px-8 lg:bg-white">
            <Link href="/" class="mb-8 lg:hidden" aria-label="Task Pro home"><BrandLogo size="lg" /></Link>

            <div :class="['page-enter w-full', wide ? 'max-w-3xl' : 'max-w-md']">
                <div class="rounded-2xl border border-gray-200/80 bg-white p-6 shadow-md sm:p-8 lg:border-transparent lg:p-2 lg:shadow-none">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
