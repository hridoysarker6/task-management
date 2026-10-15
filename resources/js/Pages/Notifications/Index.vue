<script setup>
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    notifications: { type: Object, required: true }, // paginator
    unread: { type: Number, default: 0 },
    filters: { type: Object, required: true },
    canSetPreferences: Boolean,
});

const when = (iso) => new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const open = async (n) => {
    if (!n.read) {
        try {
            await axios.post(route('notifications.read', n.id));
        } catch {
            /* still go there */
        }
    }
    if (n.url) router.visit(n.url);
    else router.reload({ preserveScroll: true });
};
const readAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Notifications" />

    <AppLayout>
        <PageHeader title="Notifications" :subtitle="unread ? `${unread} unread` : 'All read'">
            <template #actions>
                <Btn v-if="canSetPreferences" variant="secondary" :href="route('notifications.preferences')">Settings</Btn>
                <Btn v-if="unread" @click="readAll">Mark all read</Btn>
            </template>
        </PageHeader>

        <nav class="mb-4 flex gap-1 border-b border-gray-200" aria-label="Filter">
            <Link :href="route('notifications.index')" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', !filters.unread ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800']">All</Link>
            <Link :href="route('notifications.index', { unread: 1 })" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', filters.unread ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800']">Unread</Link>
        </nav>

        <EmptyState v-if="!notifications.data.length" :title="filters.unread ? 'Nothing unread' : 'No notifications yet'" description="Assignments, mentions, comments and reminders show up here." />

        <ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            <li v-for="n in notifications.data" :key="n.id">
                <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-gray-50" @click="open(n)">
                    <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-indigo-600']" />
                    <span class="min-w-0 flex-1">
                        <span :class="['block text-sm', n.read ? 'text-gray-600' : 'font-medium text-gray-900']">{{ n.title }}</span>
                        <span v-if="n.body" class="block whitespace-pre-line text-sm text-gray-500">{{ n.body }}</span>
                    </span>
                    <span class="shrink-0 text-xs text-gray-400">{{ when(n.created_at) }}</span>
                </button>
            </li>
        </ul>

        <div class="mt-4"><Pagination :links="notifications.links" /></div>
    </AppLayout>
</template>
