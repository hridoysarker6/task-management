<script setup>
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    counts: { type: Object, required: true },
    queue: { type: Array, required: true },
});

const cards = [
    { status: 'pending', label: 'Pending review', color: 'text-amber-600' },
    { status: 'info_requested', label: 'Info requested', color: 'text-blue-600' },
    { status: 'active', label: 'Active', color: 'text-green-600' },
    { status: 'suspended', label: 'Suspended', color: 'text-red-600' },
    { status: 'rejected', label: 'Rejected', color: 'text-gray-600' },
];

const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Platform dashboard" />

    <AdminLayout>
        <PageHeader title="Platform dashboard" subtitle="Companies on the platform and applications waiting for review." />

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <Link
                v-for="c in cards"
                :key="c.status"
                :href="route('admin.companies.index', { status: c.status })"
                class="rounded-xl border border-gray-200 bg-white p-4 transition hover:border-indigo-300 hover:shadow-sm"
            >
                <div :class="['text-2xl font-semibold', c.color]">{{ counts[c.status] }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ c.label }}</div>
            </Link>
        </div>

        <h2 class="mb-3 mt-8 text-sm font-semibold text-gray-900">Review queue</h2>
        <EmptyState v-if="!queue.length" title="Nothing to review" description="New applications appear here once the applicant has verified their email." />
        <ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            <li v-for="c in queue" :key="c.id">
                <Link :href="route('admin.companies.show', c.id)" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ c.name }}</div>
                        <div class="text-xs text-gray-500">{{ c.applicant?.name }} &middot; {{ c.applicant?.email }}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-400">{{ formatDate(c.created_at) }}</span>
                        <Badge color="yellow">Pending</Badge>
                    </div>
                </Link>
            </li>
        </ul>
    </AdminLayout>
</template>
