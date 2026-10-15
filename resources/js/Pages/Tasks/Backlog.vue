<script setup>
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import QuickAdd from '@/Components/Tasks/QuickAdd.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import TaskFilterBar from '@/Components/Tasks/TaskFilterBar.vue';
import TaskLink from '@/Components/Tasks/TaskLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { clearSelectable, isSelected, setSelectable } from '@/Composables/useShortcuts';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Bars3Icon, PlusIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, ref, watch } from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({
    project: { type: Object, required: true },
    tasks: { type: Array, required: true },
    truncated: Boolean,
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    canReorder: Boolean,
    canCreate: Boolean,
});

const list = ref([]);
watch(() => props.tasks, (t) => (list.value = [...t]), { immediate: true });

// J / K move through the backlog in order.
watch(() => list.value.map((t) => t.key), setSelectable, { immediate: true });
onBeforeUnmount(clearSelectable);

const error = ref('');
const onChange = async (evt) => {
    if (!evt.moved) return;
    const i = evt.moved.newIndex;
    error.value = '';
    try {
        await axios.post(route('tasks.rank', evt.moved.element.key), { prev_id: list.value[i - 1]?.id ?? null, next_id: list.value[i + 1]?.id ?? null });
        router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e.response?.status === 403 ? 'You are not allowed to reorder the backlog.' : 'The order could not be saved.';
        router.reload({ preserveScroll: true });
    }
};

const visit = (filters) => router.get(route('projects.backlog', props.project.id), filters, { preserveState: true, preserveScroll: true, replace: true });
const create = () => window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id } }));
const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');
</script>

<template>
    <Head :title="`${project.key} · Backlog`" />

    <AppLayout>
        <ProjectHeader :project="project" active="backlog">
            <template #actions>
                <Btn v-if="canCreate && !project.is_archived" @click="create"><PlusIcon class="h-4 w-4" /> Create</Btn>
            </template>
        </ProjectHeader>

        <TaskFilterBar mode="backlog" :filters="filters" :options="{ ...options, statuses: [] }" :project-id="project.id" @apply="visit" />

        <p v-if="error" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-if="truncated" class="mb-3 rounded-lg bg-yellow-50 px-3 py-2 text-sm text-yellow-800">Only the first 500 tasks are shown. Use the filters to narrow the list down.</p>
        <p class="mb-2 text-sm text-gray-500">
            {{ list.length }} task{{ list.length === 1 ? '' : 's' }} not started<template v-if="canReorder"> · drag to set the order they will be done in</template>
        </p>

        <EmptyState v-if="!list.length" title="The backlog is empty" description="Tasks that have not been started appear here, in priority order." />

        <draggable v-else v-model="list" item-key="id" handle=".handle" :disabled="!canReorder" ghost-class="opacity-40" class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white" @change="onChange">
            <template #item="{ element: t, index }">
                <div :class="['flex items-center gap-3 px-3 py-2 text-sm', isSelected(t.key) ? 'bg-violet-50 ring-1 ring-inset ring-violet-300' : '']" :data-task="t.key" :data-task-key="t.key">
                    <button v-if="canReorder" type="button" class="handle cursor-grab text-gray-300 hover:text-gray-600 active:cursor-grabbing" :aria-label="`Drag ${t.key}`"><Bars3Icon class="h-5 w-5" /></button>
                    <span class="w-6 text-right text-xs text-gray-400">{{ index + 1 }}</span>
                    <TaskBits kind="type" :value="t.type" />
                    <TaskLink :task-key="t.key" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</TaskLink>
                    <TaskLink :task-key="t.key" class="min-w-0 flex-1 truncate text-gray-900 hover:text-indigo-600">{{ t.title }}</TaskLink>
                    <span v-for="l in t.labels.slice(0, 2)" :key="l.id" class="hidden rounded-full px-1.5 py-0.5 text-[10px] font-medium text-white sm:inline" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
                    <span v-if="t.epic" class="hidden max-w-[10rem] truncate rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-700 md:inline" :title="t.epic.title">{{ t.epic.key }}</span>
                    <span v-if="t.due_date" :class="['text-xs', t.overdue ? 'font-medium text-red-600' : 'text-gray-500']">{{ fmt(t.due_date) }}</span>
                    <TaskBits kind="priority" :value="t.priority" />
                    <span v-if="t.story_points !== null" class="rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600" title="Story points">{{ t.story_points }}</span>
                    <Avatar v-if="t.assignee" :name="t.assignee.name" size="sm" />
                    <span v-else class="h-6 w-6 rounded-full border border-dashed border-gray-300" title="Unassigned" />
                </div>
            </template>
        </draggable>

        <div v-if="canCreate && !project.is_archived" class="mt-3 max-w-md"><QuickAdd :project-id="project.id" label="Add to the backlog" /></div>
    </AppLayout>
</template>
