<script setup>
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon, UsersIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    projects: { type: Object, required: true }, // paginator
    filters: { type: Object, required: true },
    departments: { type: Array, required: true },
    canCreate: { type: Boolean, default: false },
});

const q = ref(props.filters.q);
const status = ref(props.filters.status);
const departmentId = ref(props.filters.department_id);
const mine = ref(props.filters.mine);

const go = () =>
    router.get(
        route('projects.index'),
        { status: status.value === 'open' ? undefined : status.value, q: q.value || undefined, department_id: departmentId.value || undefined, mine: mine.value ? 1 : undefined },
        { preserveState: true, replace: true },
    );
let timer;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(go, 300);
});
watch([status, departmentId, mine], go);

const statusOptions = [
    { value: 'open', label: 'Open projects' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
    { value: 'all', label: 'All' },
];
const departmentOptions = computed(() => [{ value: null, label: 'All departments' }, ...props.departments.map((d) => ({ value: d.id, label: d.name }))]);

const statusColor = { active: 'green', on_hold: 'yellow', completed: 'blue', archived: 'gray' };
const statusLabel = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null);
</script>

<template>
    <Head title="Projects" />

    <AppLayout>
        <PageHeader title="Projects" subtitle="The work your company is running.">
            <template #actions>
                <Btn v-if="canCreate" :href="route('projects.create')"><PlusIcon class="h-4 w-4" /> New project</Btn>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <input v-model="q" type="search" placeholder="Search name or key…" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search projects" />
            <SelectInput v-model="status" :options="statusOptions" class="!w-40" aria-label="Filter by status" />
            <SelectInput v-model="departmentId" :options="departmentOptions" class="!w-44" aria-label="Filter by department" />
            <label class="ml-1 flex items-center gap-2 text-sm text-gray-700">
                <input v-model="mine" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> My projects only
            </label>
        </div>

        <EmptyState v-if="!projects.data.length" title="No projects found" :description="canCreate ? 'Create your first project to get started.' : 'You have not been added to any project yet.'">
            <Btn v-if="canCreate" :href="route('projects.create')">New project</Btn>
        </EmptyState>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="p in projects.data"
                :key="p.id"
                :href="route('projects.show', p.id)"
                class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 transition hover:border-indigo-300 hover:shadow-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="rounded-md bg-indigo-50 px-2 py-0.5 font-mono text-xs font-semibold text-indigo-700">{{ p.key }}</span>
                    <Badge :color="statusColor[p.status]">{{ statusLabel[p.status] }}</Badge>
                </div>
                <h2 class="mt-2 font-semibold text-gray-900">{{ p.name }}</h2>
                <p v-if="p.description" class="mt-1 line-clamp-2 text-sm text-gray-500">{{ p.description }}</p>
                <dl class="mt-auto space-y-1 pt-4 text-xs text-gray-500">
                    <div v-if="p.manager">Manager: <span class="font-medium text-gray-700">{{ p.manager }}</span></div>
                    <div v-if="p.department">{{ p.department }}</div>
                    <div v-if="p.start_date || p.end_date">{{ fmt(p.start_date) ?? '…' }} &rarr; {{ fmt(p.end_date) ?? '…' }}</div>
                    <div class="flex items-center gap-1"><UsersIcon class="h-3.5 w-3.5" /> {{ p.members }} {{ p.members === 1 ? 'member' : 'members' }}</div>
                </dl>
            </Link>
        </div>

        <div class="mt-4"><Pagination :links="projects.links" /></div>
    </AppLayout>
</template>
