<script setup>
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, required: true },
    tasks: { type: Array, required: true },
});

const fmt = (d) => new Date(d).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const restore = (t) => router.post(route('tasks.restore', t.key), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="`${project.key} · Deleted tasks`" />

    <AppLayout>
        <PageHeader :title="`Deleted tasks · ${project.key}`" subtitle="Restore a task to bring it back with its sub-tasks.">
            <template #actions><Btn variant="secondary" :href="route('projects.show', project.id)">Back to project</Btn></template>
        </PageHeader>

        <EmptyState v-if="!tasks.length" title="Nothing deleted" description="Tasks you delete in this project appear here." />

        <ul v-else class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <li v-for="t in tasks" :key="t.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <TaskBits kind="type" :value="t.type" />
                <span class="font-mono text-xs font-semibold text-gray-500">{{ t.key }}</span>
                <span class="min-w-0 flex-1 truncate text-gray-800">{{ t.title }}</span>
                <span class="text-xs text-gray-400">deleted {{ fmt(t.deleted_at) }}</span>
                <Btn v-if="t.can_restore" size="sm" variant="secondary" @click="restore(t)">Restore</Btn>
            </li>
        </ul>
    </AppLayout>
</template>
