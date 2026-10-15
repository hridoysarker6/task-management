<script setup>
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    person: { type: Object, required: true },
    trail: { type: Array, default: () => [] },
    reports: { type: Array, default: () => [] },
    show: { type: String, required: true },
    tasks: { type: Array, default: () => [] },
    truncated: Boolean,
});

const filters = [
    { key: 'open', label: 'Open' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'blocked', label: 'Blocked' },
    { key: 'done', label: 'Done' },
    { key: 'all', label: 'All' },
];
const count = (key) => ({ open: props.person.open, overdue: props.person.overdue, blocked: props.person.blocked, done: props.person.done }[key]);
const fmt = (x) => (x ? new Date(x + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');
const link = (id, show) => route('drill', { membership: id, show });
const card = 'rounded-xl border border-gray-200 bg-white p-4';
</script>

<template>
    <Head :title="person.name" />

    <AppLayout>
        <nav class="mb-2 flex flex-wrap items-center gap-1 text-sm text-gray-500" aria-label="Reporting line">
            <Link :href="route('dashboard')" class="hover:text-gray-900">Dashboard</Link>
            <template v-for="t in trail" :key="t.id">
                <ChevronRightIcon class="h-3.5 w-3.5" />
                <Link v-if="t.id !== person.id" :href="route('drill', t.is_me ? undefined : t.id)" class="hover:text-gray-900">{{ t.is_me ? 'Me' : t.name }}</Link>
                <span v-else class="font-medium text-gray-900">{{ t.is_me ? 'Me' : t.name }}</span>
            </template>
        </nav>

        <PageHeader :title="person.name" :subtitle="`${person.role}${person.people ? ` · ${person.people} people in this line` : ''}`" />

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">Complete</p><p class="mt-1 text-2xl font-semibold">{{ person.percent }}%</p></div>
            <Link v-for="f in filters.slice(0, 4)" :key="f.key" :href="link(person.is_me ? undefined : person.id, f.key)" :class="[card, 'hover:border-indigo-300', show === f.key ? 'border-indigo-400 ring-1 ring-indigo-300' : '']">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ f.label }}</p>
                <p :class="['mt-1 text-2xl font-semibold', f.key === 'overdue' && count(f.key) ? 'text-red-600' : '']">{{ count(f.key) }}</p>
            </Link>
        </div>

        <section v-if="reports.length" class="mb-6" aria-label="Direct reports">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">Reporting to {{ person.is_me ? 'you' : person.name }}</h2>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-2">Person</th><th class="px-3 py-2 text-right">Complete</th><th class="px-3 py-2 text-right">Open</th><th class="px-3 py-2 text-right">Overdue</th><th class="px-3 py-2 text-right">Blocked</th><th class="px-3 py-2 text-right">People</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in reports" :key="r.id" class="hover:bg-gray-50">
                            <td class="px-4 py-2"><Link :href="route('drill', r.id)" class="flex items-center gap-2 font-medium text-gray-900 hover:text-indigo-600"><Avatar :name="r.name" size="sm" /> {{ r.name }} <span class="text-xs font-normal text-gray-400">{{ r.role }}</span></Link></td>
                            <td class="px-3 py-2 text-right">{{ r.percent }}%</td>
                            <td class="px-3 py-2 text-right"><Link :href="link(r.id, 'open')" class="hover:text-indigo-600">{{ r.open }}</Link></td>
                            <td :class="['px-3 py-2 text-right', r.overdue ? 'font-medium text-red-600' : 'text-gray-400']"><Link :href="link(r.id, 'overdue')">{{ r.overdue }}</Link></td>
                            <td class="px-3 py-2 text-right"><Link :href="link(r.id, 'blocked')" class="hover:text-indigo-600">{{ r.blocked }}</Link></td>
                            <td class="px-3 py-2 text-right text-gray-500">{{ r.people || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Tasks">
            <div class="mb-2 flex items-center gap-2">
                <h2 class="text-sm font-semibold text-gray-900">Tasks</h2>
                <nav class="flex gap-1">
                    <Link v-for="f in filters" :key="f.key" :href="link(person.is_me ? undefined : person.id, f.key)" :class="['rounded-full px-2.5 py-0.5 text-xs', show === f.key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">{{ f.label }}</Link>
                </nav>
            </div>
            <EmptyState v-if="!tasks.length" title="No tasks" description="Nothing matches this filter." />
            <ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                <li v-for="t in tasks" :key="t.id" class="flex items-center gap-2 px-4 py-2 text-sm">
                    <TaskBits kind="type" :value="t.type" />
                    <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                    <Link :href="route('tasks.show', t.key)" class="min-w-0 flex-1 truncate text-gray-900 hover:text-indigo-600">{{ t.title }}</Link>
                    <span v-if="t.assignee" class="hidden text-xs text-gray-500 sm:inline">{{ t.assignee.name }}</span>
                    <TaskBits kind="status" :value="t.status" />
                    <span v-if="t.due_date" :class="['text-xs', t.overdue ? 'font-medium text-red-600' : 'text-gray-500']">{{ fmt(t.due_date) }}</span>
                </li>
            </ul>
            <p v-if="truncated" class="mt-2 text-xs text-gray-400">Showing the first 100.</p>
        </section>
    </AppLayout>
</template>
