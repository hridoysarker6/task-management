<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    lines: { type: Object, required: true }, // paginator
    filters: { type: Object, required: true },
    groups: { type: Object, required: true },
    scope: { type: String, required: true }, // company | platform
    routeName: { type: String, required: true },
    companies: { type: Array, default: () => [] },
});

const Layout = computed(() => (props.scope === 'platform' ? AdminLayout : AppLayout));
const draft = reactive({ group: '', actor: '', from: '', to: '', organization_id: '', ...props.filters });
const apply = () => router.get(route(props.routeName), Object.fromEntries(Object.entries(draft).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
const open = ref(new Set());
const toggle = (id) => {
    const s = new Set(open.value);
    s.has(id) ? s.delete(id) : s.add(id);
    open.value = s;
};

const colors = { role: 'purple', permission: 'purple', member: 'blue', invitation: 'indigo', project: 'green', company: 'yellow' };
const when = (iso) => new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const show = (v) => (Array.isArray(v) ? (v.length ? v.join(', ') : '—') : v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));
const keys = (l) => [...new Set([...Object.keys(l.old ?? {}), ...Object.keys(l.new ?? {})])];
const field = 'rounded-lg border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head title="Audit log" />

    <component :is="Layout">
        <PageHeader title="Audit log" :subtitle="scope === 'platform' ? 'Admin and security actions across the platform.' : 'Who changed roles, permissions, people and projects in your company.'" />

        <form class="mb-5 flex flex-wrap items-end gap-3 text-sm" @submit.prevent="apply">
            <label class="block"><span class="block text-xs text-gray-500">Kind</span>
                <select v-model="draft.group" :class="field" @change="apply"><option value="">Everything</option><option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option></select>
            </label>
            <label v-if="scope === 'platform'" class="block"><span class="block text-xs text-gray-500">Company</span>
                <select v-model="draft.organization_id" :class="field" @change="apply"><option value="">All</option><option value="platform">Platform only</option><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </label>
            <label class="block"><span class="block text-xs text-gray-500">Done by</span><input v-model="draft.actor" type="search" placeholder="Name" :class="[field, 'w-40']" @change="apply" /></label>
            <label class="block"><span class="block text-xs text-gray-500">From</span><input v-model="draft.from" type="date" :class="field" @change="apply" /></label>
            <label class="block"><span class="block text-xs text-gray-500">To</span><input v-model="draft.to" type="date" :class="field" @change="apply" /></label>
        </form>

        <EmptyState v-if="!lines.data.length" title="Nothing recorded" description="Admin actions appear here as they happen." />

        <ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            <li v-for="l in lines.data" :key="l.id" class="px-4 py-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :color="colors[l.group] ?? 'gray'">{{ l.label }}</Badge>
                    <span v-if="l.summary" class="font-medium text-gray-900">{{ l.summary }}</span>
                    <span v-if="l.company" class="text-xs text-gray-500">· {{ l.company }}</span>
                    <span class="ml-auto text-xs text-gray-400">{{ when(l.created_at) }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500">By {{ l.actor }}<template v-if="l.ip"> · {{ l.ip }}</template>
                    <button v-if="l.old || l.new" type="button" class="ml-2 text-indigo-600 hover:underline" :aria-expanded="open.has(l.id)" @click="toggle(l.id)">{{ open.has(l.id) ? 'Hide changes' : 'Show changes' }}</button>
                </p>
                <table v-if="open.has(l.id)" class="mt-2 w-full max-w-2xl text-xs">
                    <thead class="text-left text-gray-400"><tr><th class="py-1 pr-3 font-medium">Field</th><th class="py-1 pr-3 font-medium">Before</th><th class="py-1 font-medium">After</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="k in keys(l)" :key="k"><td class="py-1 pr-3 font-mono text-gray-600">{{ k }}</td><td class="py-1 pr-3 text-gray-500">{{ show(l.old?.[k]) }}</td><td class="py-1 text-gray-900">{{ show(l.new?.[k]) }}</td></tr>
                    </tbody>
                </table>
            </li>
        </ul>

        <div class="mt-4"><Pagination :links="lines.links" /></div>
    </component>
</template>
