<script setup>
import BarList from '@/Components/Charts/BarList.vue';
import BurndownChart from '@/Components/Charts/BurndownChart.vue';
import VelocityChart from '@/Components/Charts/VelocityChart.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    report: { type: String, required: true },
    tabs: { type: Array, required: true },
    data: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    canExport: Boolean,
});

const draft = reactive({ project_id: '', sprint_id: '', person_id: '', from: '', to: '', group: 'person', include_former: false, ...props.filters });

const clean = () => Object.fromEntries(Object.entries(draft).filter(([, v]) => v !== '' && v !== false && v !== null));
const apply = () => router.get(route('reports.index', props.report), clean(), { preserveState: true, preserveScroll: true, replace: true });
const pickProject = () => {
    draft.sprint_id = '';
    apply();
};
const exportUrl = (format) => route('reports.export', { report: props.report, format, ...clean() });

const usesProject = computed(() => ['sprint', 'workload', 'overdue', 'time'].includes(props.report));
const usesPerson = computed(() => ['workload', 'overdue'].includes(props.report));
const usesDates = computed(() => props.report === 'time');
const usesFormer = computed(() => ['workload', 'overdue', 'time'].includes(props.report));
const alignRight = (c) => (c.type === 'number' ? 'text-right' : 'text-left');
const field = 'rounded-lg border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head :title="data.title" />

    <AppLayout>
        <PageHeader :title="data.title" />

        <nav class="mb-4 flex gap-1 border-b border-gray-200" aria-label="Reports">
            <Link v-for="t in tabs" :key="t.key" :href="route('reports.index', t.key)" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', report === t.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800']">{{ t.label }}</Link>
        </nav>

        <form class="mb-5 flex flex-wrap items-end gap-3 text-sm" @submit.prevent="apply">
            <label v-if="usesProject" class="block"><span class="block text-xs text-gray-500">Project</span>
                <select v-model="draft.project_id" :class="field" @change="pickProject"><option value="">{{ report === 'sprint' ? 'Choose a project' : 'All projects' }}</option><option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.key }} · {{ p.name }}</option></select>
            </label>
            <label v-if="report === 'sprint' && options.sprints.length" class="block"><span class="block text-xs text-gray-500">Sprint</span>
                <select v-model="draft.sprint_id" :class="field" @change="apply"><option value="">Active or latest</option><option v-for="s in options.sprints" :key="s.id" :value="s.id">{{ s.name }} ({{ s.status }})</option></select>
            </label>
            <label v-if="usesPerson || report === 'time'" class="block"><span class="block text-xs text-gray-500">Person</span>
                <select v-model="draft.person_id" :class="field" @change="apply"><option value="">Everyone</option><option v-for="p in options.people" :key="p.id" :value="p.id">{{ p.name }}</option></select>
            </label>
            <template v-if="usesDates">
                <label class="block"><span class="block text-xs text-gray-500">From</span><input v-model="draft.from" type="date" :class="field" @change="apply" /></label>
                <label class="block"><span class="block text-xs text-gray-500">To</span><input v-model="draft.to" type="date" :class="field" @change="apply" /></label>
                <label class="block"><span class="block text-xs text-gray-500">Group by</span><select v-model="draft.group" :class="field" @change="apply"><option value="person">Person</option><option value="project">Project</option></select></label>
            </template>
            <label v-if="usesFormer" class="flex items-center gap-2 pb-2 text-gray-700"><input v-model="draft.include_former" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="apply" /> Include former employees</label>

            <div v-if="canExport" class="ml-auto flex items-center gap-2 pb-0.5">
                <a :href="exportUrl('csv')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50"><ArrowDownTrayIcon class="h-4 w-4" /> CSV</a>
                <a :href="exportUrl('xlsx')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
                <a :href="exportUrl('print')" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50"><PrinterIcon class="h-4 w-4" /> Print / PDF</a>
            </div>
        </form>

        <dl v-if="Object.keys(data.summary).length" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="(v, k) in data.summary" :key="k" class="rounded-xl border border-gray-200 bg-white p-3"><dt class="text-xs text-gray-500">{{ k }}</dt><dd class="mt-0.5 text-xl font-semibold text-gray-900">{{ v }}</dd></div>
        </dl>

        <div v-if="data.chart?.type === 'sprint'" class="mb-5 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-gray-200 bg-white p-4"><h2 class="mb-2 text-sm font-semibold text-gray-900">Burndown · {{ data.chart.sprint.name }}</h2><BurndownChart :days="data.chart.burndown.days" :committed="data.chart.burndown.committed" /></section>
            <section class="rounded-xl border border-gray-200 bg-white p-4"><h2 class="mb-2 text-sm font-semibold text-gray-900">Velocity</h2><VelocityChart v-if="data.chart.velocity.sprints.length" :sprints="data.chart.velocity.sprints" :average="data.chart.velocity.average" /><p v-else class="text-sm text-gray-400">No finished sprint yet.</p></section>
        </div>
        <section v-else-if="data.chart?.type === 'bars' && data.chart.items.length" class="mb-5 rounded-xl border border-gray-200 bg-white p-4"><BarList :items="data.chart.items" :unit="report === 'time' ? ' h' : ''" /></section>

        <EmptyState v-if="!data.rows.length" :title="report === 'sprint' && !filters.project_id ? 'Choose a project' : 'Nothing to report'" :description="report === 'sprint' && !filters.project_id ? 'Pick a project to see its sprint report, burndown and velocity.' : 'No rows match these filters.'" />

        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500"><tr><th v-for="c in data.columns" :key="c.key" :class="['whitespace-nowrap px-3 py-2', alignRight(c)]">{{ c.label }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="(row, i) in data.rows" :key="i" class="hover:bg-gray-50">
                        <td v-for="c in data.columns" :key="c.key" :class="['px-3 py-2', alignRight(c), c.key === 'title' ? 'max-w-md truncate' : 'whitespace-nowrap']">
                            <Link v-if="c.key === 'key'" :href="route('tasks.show', row.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ row.key }}</Link>
                            <template v-else>{{ row[c.key] }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
