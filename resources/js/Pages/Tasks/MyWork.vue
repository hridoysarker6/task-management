<script setup>
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    tab: { type: String, required: true },
    includeDone: { type: Boolean, default: false },
    tasks: { type: Object, required: true }, // paginator
    counts: { type: Object, required: true },
});

const tabs = [
    { key: 'assigned', label: 'Assigned to me' },
    { key: 'reported', label: 'Reported by me' },
    { key: 'watching', label: 'Watching' },
];

const done = ref(props.includeDone);
watch(done, (v) => router.get(route('my-work'), { tab: props.tab, done: v ? 1 : undefined }, { preserveState: true, replace: true }));

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');
const create = () => window.dispatchEvent(new CustomEvent('create-task'));
</script>

<template>
    <Head title="My Work" />

    <AppLayout>
        <PageHeader title="My Work" subtitle="Everything that needs you, in one place." />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1" aria-label="My work">
                <Link v-for="t in tabs" :key="t.key" :href="route('my-work', { tab: t.key, done: done ? 1 : undefined })" :class="['flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium', tab === t.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900']">
                    {{ t.label }} <span class="rounded-full bg-gray-200 px-1.5 text-xs text-gray-600">{{ counts[t.key] }}</span>
                </Link>
            </nav>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="done" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Include finished tasks</label>
        </div>

        <EmptyState v-if="!tasks.data.length" title="Nothing here" :description="tab === 'assigned' ? 'No open tasks are assigned to you.' : tab === 'reported' ? 'You have not raised any open tasks.' : 'You are not watching any open tasks.'">
            <button class="text-sm font-medium text-indigo-600 hover:underline" @click="create">Create a task</button>
        </EmptyState>

        <ul v-else class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <li v-for="t in tasks.data" :key="t.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <TaskBits kind="type" :value="t.type" />
                <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                <Link :href="route('tasks.show', t.key)" :class="['min-w-0 flex-1 truncate hover:text-indigo-600', t.status?.category === 'done' ? 'text-gray-400 line-through' : 'text-gray-900']">{{ t.title }}</Link>
                <span class="hidden text-xs text-gray-400 md:inline">{{ t.project.name }}</span>
                <TaskBits kind="priority" :value="t.priority" />
                <span v-if="t.due_date" :class="['text-xs', t.overdue ? 'font-medium text-red-600' : 'text-gray-500']" :title="t.overdue ? 'Overdue' : 'Due date'">{{ t.overdue ? 'Overdue · ' : '' }}{{ fmt(t.due_date) }}</span>
                <Avatar v-if="tab !== 'assigned' && t.assignee" :name="t.assignee.name" size="sm" />
                <TaskBits kind="status" :value="t.status" />
            </li>
        </ul>

        <div class="mt-4"><Pagination :links="tasks.links" /></div>
    </AppLayout>
</template>
