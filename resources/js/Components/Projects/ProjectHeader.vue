<script setup>
import Badge from '@/Components/UI/Badge.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { usePermissions } from '@/Composables/usePermissions';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// Title bar and tabs shared by every project page.
const props = defineProps({
    project: { type: Object, required: true }, // { id, key, name, status, is_archived, abilities }
    active: { type: String, required: true }, // overview | board | sprints | backlog | list | members | workflow | labels | settings
});

const statusColor = { active: 'green', on_hold: 'yellow', completed: 'blue', archived: 'gray' };
const statusLabel = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };

const { can } = usePermissions();

const tabs = computed(() => [
    { key: 'overview', label: 'Overview', route: 'projects.show' },
    { key: 'board', label: 'Board', route: 'projects.board' },
    ...(props.project.show_sprints ? [{ key: 'sprints', label: 'Sprints', route: 'projects.sprints.index' }] : []),
    ...(can('backlog.view') ? [{ key: 'backlog', label: 'Backlog', route: 'projects.backlog' }] : []),
    { key: 'list', label: 'List', route: 'projects.tasks.index' },
    { key: 'members', label: 'Members', route: 'projects.members.index' },
    { key: 'workflow', label: 'Workflow', route: 'projects.workflow.index' },
    { key: 'labels', label: 'Labels', route: 'projects.labels.index' },
    { key: 'settings', label: 'Settings', route: 'projects.settings.edit' },
]);
</script>

<template>
    <div class="mb-6">
        <Link :href="route('projects.index')" class="mb-3 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">
            <ArrowLeftIcon class="h-4 w-4" /> Projects
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-md bg-indigo-50 px-2 py-1 font-mono text-sm font-semibold text-indigo-700">{{ project.key }}</span>
            <h1 class="text-xl font-semibold text-gray-900">{{ project.name }}</h1>
            <Badge :color="statusColor[project.status]">{{ statusLabel[project.status] }}</Badge>
            <div v-if="$slots.actions" class="ml-auto flex items-center gap-2"><slot name="actions" /></div>
        </div>

        <p v-if="project.is_archived" class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">
            This project is archived and read-only. <template v-if="project.abilities.change_status">Restore it from Settings to make changes.</template>
        </p>

        <nav class="mt-4 flex gap-1 border-b border-gray-200" aria-label="Project sections">
            <Link
                v-for="t in tabs"
                :key="t.key"
                :href="route(t.route, project.id)"
                :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', active === t.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800']"
            >
                {{ t.label }}
            </Link>
        </nav>
    </div>
</template>
