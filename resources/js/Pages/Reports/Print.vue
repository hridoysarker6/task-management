<script setup>
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';

// A report on a plain page: no menus, black on white. Print it, or "Save as PDF" in the print dialog.
defineProps({
    data: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    generated: String,
    company: String,
});

const print = () => window.print();
onMounted(() => setTimeout(print, 400));
</script>

<template>
    <Head :title="data.title" />

    <main class="mx-auto max-w-5xl bg-white p-8 text-gray-900 print:p-0">
        <header class="mb-6 border-b border-gray-300 pb-3">
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ company }}</p>
            <h1 class="text-2xl font-semibold">{{ data.title }}</h1>
            <p class="text-xs text-gray-500">Generated {{ generated }}</p>
        </header>

        <dl v-if="Object.keys(data.summary).length" class="mb-6 flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div v-for="(v, k) in data.summary" :key="k"><dt class="inline text-gray-500">{{ k }}:</dt> <dd class="inline font-semibold">{{ v }}</dd></div>
        </dl>

        <table class="w-full border-collapse text-sm">
            <thead><tr><th v-for="c in data.columns" :key="c.key" :class="['border-b-2 border-gray-400 px-2 py-1.5 font-semibold', c.type === 'number' ? 'text-right' : 'text-left']">{{ c.label }}</th></tr></thead>
            <tbody>
                <tr v-for="(row, i) in data.rows" :key="i" class="break-inside-avoid">
                    <td v-for="c in data.columns" :key="c.key" :class="['border-b border-gray-200 px-2 py-1', c.type === 'number' ? 'text-right' : 'text-left']">{{ row[c.key] }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="!data.rows.length" class="py-6 text-center text-gray-500">Nothing to report.</p>

        <button class="mt-8 rounded border border-gray-300 px-3 py-1.5 text-sm print:hidden" @click="print">Print / Save as PDF</button>
    </main>
</template>
