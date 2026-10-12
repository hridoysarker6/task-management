<script setup>
import Badge from '@/Components/UI/Badge.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    companies: { type: Object, required: true }, // paginator
    status: { type: String, required: true },
    search: { type: String, default: '' },
    tabs: { type: Array, required: true }, // [{ status, count }]
});

const labels = {
    pending: 'Pending',
    info_requested: 'Info requested',
    active: 'Active',
    rejected: 'Rejected',
    suspended: 'Suspended',
};

const columns = [
    { key: 'name', label: 'Company' },
    { key: 'applicant', label: 'Contact person' },
    { key: 'industry', label: 'Industry' },
    { key: 'created_at', label: 'Registered' },
];

const q = ref(props.search);
let timer;
watch(q, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('admin.companies.index'), { status: props.status, q: value || undefined }, { preserveState: true, replace: true });
    }, 300);
});

const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Companies" />

    <AdminLayout>
        <PageHeader title="Companies" subtitle="Review registrations and manage companies on the platform." />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1" aria-label="Company status">
                <Link
                    v-for="tab in tabs"
                    :key="tab.status"
                    :href="route('admin.companies.index', { status: tab.status, q: q || undefined })"
                    :class="[
                        'flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium',
                        status === tab.status ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900',
                    ]"
                >
                    {{ labels[tab.status] }}
                    <span class="rounded-full bg-gray-200 px-1.5 text-xs text-gray-600">{{ tab.count }}</span>
                </Link>
            </nav>

            <input
                v-model="q"
                type="search"
                placeholder="Search name, code or contact…"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-72"
                aria-label="Search companies"
            />
        </div>

        <DataTable :columns="columns" :rows="companies.data" empty-text="No companies in this tab.">
            <template #cell-name="{ row }">
                <Link :href="route('admin.companies.show', row.id)" class="font-medium text-indigo-600 hover:underline">{{ row.name }}</Link>
                <div class="text-xs text-gray-400">{{ row.slug }}</div>
            </template>
            <template #cell-applicant="{ row }">
                <div>{{ row.applicant?.name }}</div>
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    {{ row.applicant?.email }}
                    <Badge v-if="!row.email_verified" color="yellow">Email unverified</Badge>
                </div>
            </template>
            <template #cell-industry="{ row }">{{ row.industry ?? '—' }}</template>
            <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
            <template #footer><Pagination :links="companies.links" /></template>
        </DataTable>
    </AdminLayout>
</template>
