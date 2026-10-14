<script setup>
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    people: { type: Array, required: true },
    projects: { type: Array, required: true },
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
});

const openIds = ref(new Set(props.people.filter((p) => p.overdue).map((p) => p.id)));
const toggle = (id) => {
    const s = new Set(openIds.value);
    s.has(id) ? s.delete(id) : s.add(id);
    openIds.value = s;
};

const visit = (extra) => router.get(route('team'), { ...props.filters, ...extra }, { preserveState: true, replace: true });
const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');
</script>

<template>
    <Head title="Team" />

    <AppLayout>
        <PageHeader title="Team" :subtitle="`${totals.people} people · ${totals.open} open tasks · ${totals.overdue} overdue`" />

        <div class="mb-4 flex flex-wrap items-center gap-4 text-sm">
            <label class="flex items-center gap-2 text-gray-600">
                Project
                <select :value="filters.project_id ?? ''" class="rounded-lg border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="visit({ project_id: $event.target.value || undefined })">
                    <option value="">All projects</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.key }} · {{ p.name }}</option>
                </select>
            </label>
            <label class="flex items-center gap-2 text-gray-600">
                <input type="checkbox" :checked="filters.include_me" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="visit({ include_me: $event.target.checked ? 1 : undefined })" /> Include me
            </label>
        </div>

        <EmptyState v-if="!people.length" title="Nobody reports to you yet" description="People below you in the reporting line, and their open work, show up here." />

        <ul v-else class="space-y-3">
            <li v-for="p in people" :key="p.id" class="rounded-xl border border-gray-200 bg-white">
                <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left" :aria-expanded="openIds.has(p.id)" @click="toggle(p.id)">
                    <component :is="openIds.has(p.id) ? ChevronDownIcon : ChevronRightIcon" class="h-4 w-4 text-gray-400" />
                    <Avatar :name="p.name" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-gray-900">{{ p.name }}</span>
                        <span class="block text-xs text-gray-500">{{ p.role }}</span>
                    </span>
                    <span class="text-sm text-gray-600">{{ p.open }} open</span>
                    <span class="hidden text-sm text-gray-500 sm:inline">{{ p.in_progress }} in progress</span>
                    <span v-if="p.overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">{{ p.overdue }} overdue</span>
                </button>

                <div v-if="openIds.has(p.id)" class="border-t border-gray-100">
                    <p v-if="!p.tasks.length" class="px-4 py-3 text-sm text-gray-400">No open tasks.</p>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="t in p.tasks" :key="t.id" class="flex items-center gap-3 px-4 py-2 text-sm">
                            <TaskBits kind="type" :value="t.type" />
                            <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                            <Link :href="route('tasks.show', t.key)" class="min-w-0 flex-1 truncate text-gray-900 hover:text-indigo-600">{{ t.title }}</Link>
                            <TaskBits kind="status" :value="t.status" />
                            <span v-if="t.due_date" :class="['text-xs', t.overdue ? 'font-medium text-red-600' : 'text-gray-500']">{{ fmt(t.due_date) }}</span>
                        </li>
                    </ul>
                    <p v-if="p.more" class="px-4 py-2 text-xs text-gray-400">…and {{ p.more }} more.</p>
                </div>
            </li>
        </ul>
    </AppLayout>
</template>
