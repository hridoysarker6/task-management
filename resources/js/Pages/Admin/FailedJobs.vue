<script setup>
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    jobs: { type: Array, required: true },
    total: { type: Number, default: 0 },
    pending: { type: Number, default: 0 },
});

const retry = (j) => router.post(route('admin.failed-jobs.retry', j.uuid), {}, { preserveScroll: true });
const dismiss = (j) => router.delete(route('admin.failed-jobs.destroy', j.uuid), { preserveScroll: true });
</script>

<template>
    <Head title="Failed jobs" />

    <AdminLayout>
        <PageHeader title="Failed jobs" :subtitle="`${total} failed · ${pending} waiting in the queue`" />

        <p class="mb-4 text-sm text-gray-500">Emails and notifications are sent in the background. When one cannot be sent it lands here, so nothing is lost silently. Run <code class="rounded bg-gray-100 px-1">php artisan queue:work</code> to process the queue.</p>

        <EmptyState v-if="!jobs.length" title="Nothing has failed" description="Every background job has run." />

        <ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            <li v-for="j in jobs" :key="j.uuid" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-900">{{ j.name }} <span class="font-normal text-gray-400">· {{ j.queue }}</span></p>
                    <p class="truncate text-xs text-red-600" :title="j.error">{{ j.error }}</p>
                    <p class="text-xs text-gray-400">{{ j.failed_at }}</p>
                </div>
                <Btn size="sm" variant="secondary" @click="retry(j)">Retry</Btn>
                <Btn size="sm" variant="ghost" @click="dismiss(j)">Dismiss</Btn>
            </li>
        </ul>
    </AdminLayout>
</template>
