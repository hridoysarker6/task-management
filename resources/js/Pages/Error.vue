<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// 403 / 404 / 419 / 500 / 503. It reads no shared props, so it also works for guests and when the app itself failed.
const props = defineProps({ status: { type: Number, required: true } });

const copy = computed(
    () =>
        ({
            403: { title: 'You do not have access to this', text: 'Ask your Company Admin if you think you should. Your role decides what you can open.' },
            404: { title: 'We could not find that page', text: 'It may have been moved or deleted, or the link is wrong. Tasks and projects you cannot see also show up here.' },
            419: { title: 'This page has expired', text: 'You were away for a while. Reload the page and try again; nothing was lost.' },
            500: { title: 'Something went wrong on our side', text: 'It is not you. Try again in a moment. If it keeps happening, tell your administrator what you were doing.' },
            503: { title: 'We will be right back', text: 'The system is being updated. Please try again in a few minutes.' },
        })[props.status] ?? { title: 'Something went wrong', text: 'Please try again.' },
);

const retry = computed(() => props.status === 419 || props.status >= 500);
const reload = () => window.location.reload();
const back = () => window.history.back();
const btn = 'inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold transition active:scale-[0.98]';
</script>

<template>
    <Head :title="`${status} · ${copy.title}`" />

    <main class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-gray-50 px-6 py-12 text-center">
        <div class="pointer-events-none absolute -top-32 left-1/2 h-96 w-[40rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-violet-200/50 to-sky-200/50 blur-3xl" />

        <a href="/" class="relative mb-10" aria-label="Task Pro home"><BrandLogo size="lg" /></a>
        <p class="text-gradient relative text-7xl font-extrabold tracking-tight">{{ status }}</p>
        <h1 class="relative mt-4 text-2xl font-bold tracking-tight text-gray-900">{{ copy.title }}</h1>
        <p class="relative mt-2 max-w-md text-sm text-gray-600">{{ copy.text }}</p>

        <div class="relative mt-8 flex flex-wrap justify-center gap-3">
            <button v-if="retry" type="button" :class="[btn, 'btn-primary']" @click="reload">Reload</button>
            <a href="/dashboard" :class="[btn, retry ? 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50' : 'btn-primary']">Go to the dashboard</a>
            <button v-if="status === 403 || status === 404" type="button" :class="[btn, 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50']" @click="back">Go back</button>
        </div>
    </main>
</template>
