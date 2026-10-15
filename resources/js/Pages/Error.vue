<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
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

const reload = () => window.location.reload();
const back = () => window.history.back();
</script>

<template>
    <Head :title="`${status} · ${copy.title}`" />

    <main class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-6 py-12 text-center">
        <a href="/" class="mb-8" aria-label="Home"><ApplicationLogo class="h-10 w-10 fill-current text-indigo-600" /></a>
        <p class="text-5xl font-bold text-indigo-600">{{ status }}</p>
        <h1 class="mt-3 text-xl font-semibold text-gray-900">{{ copy.title }}</h1>
        <p class="mt-2 max-w-md text-sm text-gray-600">{{ copy.text }}</p>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <button v-if="status === 419 || status >= 500" type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="reload">Reload</button>
            <a href="/dashboard" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" :class="status === 419 || status >= 500 ? '!bg-white !text-gray-700 border border-gray-300 hover:!bg-gray-50' : ''">Go to the dashboard</a>
            <button v-if="status === 403 || status === 404" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="back">Go back</button>
        </div>
    </main>
</template>
