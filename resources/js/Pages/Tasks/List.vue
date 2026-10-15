<script setup>
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import InlineField from '@/Components/Tasks/InlineField.vue';
import QuickAdd from '@/Components/Tasks/QuickAdd.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import TaskFilterBar from '@/Components/Tasks/TaskFilterBar.vue';
import TaskLink from '@/Components/Tasks/TaskLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { assignTask, moveTask, patchTask } from '@/Composables/useTaskEdit';
import { clearSelectable, isSelected, setSelectable } from '@/Composables/useShortcuts';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, ViewColumnsIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    tasks: { type: Object, required: true }, // paginator
    filters: { type: Object, required: true },
    sort: String,
    dir: String,
    perPage: Number,
    perPageOptions: Array,
    options: { type: Object, required: true },
    assignees: { type: Array, default: () => [] },
    savedFilters: { type: Array, default: () => [] },
    canBulk: Boolean,
    bulk: Object,
    canCreate: Boolean,
    showEffort: Boolean,
});

const page = usePage();
const failed = computed(() => page.props.flash?.bulk_failed ?? []);

// --- columns the person has chosen to see ------------------------------------------------------
const allColumns = computed(() => [
    { key: 'key', label: 'Key', sort: 'key', fixed: true },
    { key: 'title', label: 'Title', sort: 'title', fixed: true },
    { key: 'type', label: 'Type', sort: 'type' },
    { key: 'status', label: 'Status', sort: 'status' },
    ...(props.options.sprints?.length ? [{ key: 'sprint', label: 'Sprint' }] : []),
    { key: 'priority', label: 'Priority', sort: 'priority' },
    { key: 'assignee', label: 'Assignee', sort: 'assignee' },
    { key: 'reporter', label: 'Reporter', sort: 'reporter' },
    { key: 'labels', label: 'Labels' },
    ...(props.showEffort ? [{ key: 'story_points', label: 'Points', sort: 'story_points' }] : []),
    { key: 'due_date', label: 'Due', sort: 'due_date' },
    { key: 'created_at', label: 'Created', sort: 'created_at' },
    { key: 'updated_at', label: 'Updated', sort: 'updated_at' },
]);
const defaults = ['type', 'status', 'priority', 'assignee', 'due_date', 'updated_at'];
const storeKey = 'task-list-columns';
const chosen = ref([...defaults]);
try {
    const saved = JSON.parse(localStorage.getItem(storeKey) ?? 'null');
    if (Array.isArray(saved)) chosen.value = saved;
} catch {
    /* storage unavailable: keep the defaults */
}
const toggleColumn = (key) => {
    chosen.value = chosen.value.includes(key) ? chosen.value.filter((k) => k !== key) : [...chosen.value, key];
    try {
        localStorage.setItem(storeKey, JSON.stringify(chosen.value));
    } catch {
        /* ignore */
    }
};
const visible = computed(() => allColumns.value.filter((c) => c.fixed || chosen.value.includes(c.key)));
const pickable = computed(() => allColumns.value.filter((c) => !c.fixed));

const pickerOpen = ref(false);
const picker = ref(null);
const onDoc = (e) => {
    if (picker.value && !picker.value.contains(e.target)) pickerOpen.value = false;
};
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));

// --- navigation: filters, sorting, page size --------------------------------------------------
const visit = (extra = {}) =>
    router.get(route('projects.tasks.index', props.project.id), { ...props.filters, sort: props.sort, dir: props.dir, per_page: props.perPage, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
const applyFilters = (f) => visit({ ...Object.fromEntries(Object.keys(props.filters).map((k) => [k, undefined])), ...f, page: undefined });
const sortBy = (key) => visit({ sort: key, dir: props.sort === key && props.dir === 'asc' ? 'desc' : 'asc', page: undefined });

// --- bulk actions -------------------------------------------------------------------------------
const selected = ref([]);
const rows = computed(() => props.tasks.data);

// --- inline editing: the choices each select offers ------------------------------------------------
const statusChoices = computed(() => props.options.statuses.map((s) => ({ value: s.id, label: s.name })));
const priorityChoices = computed(() => props.options.priorities.map((p) => ({ value: p.value, label: p.label })));
const assigneeChoices = (t) => {
    const list = props.assignees.map((p) => ({ value: p.id, label: p.name }));
    if (t.assignee && !list.some((o) => o.value === t.assignee.id)) list.unshift({ value: t.assignee.id, label: t.assignee.name });
    return [{ value: null, label: 'Unassigned' }, ...list];
};

// J / K move through these rows; Enter opens the selected one.
watch(() => rows.value.map((t) => t.key), setSelectable, { immediate: true });
onBeforeUnmount(clearSelectable);
const allOn = computed(() => rows.value.length > 0 && rows.value.every((t) => selected.value.includes(t.id)));
const toggleAll = () => (selected.value = allOn.value ? [] : rows.value.map((t) => t.id));

const action = ref('');
const target = ref('');
const closeChildren = ref(false);
const confirmDelete = ref(false);
const working = ref(false);

const openSprints = computed(() => (props.options.sprints ?? []).filter((s) => s.status !== 'completed'));
const needsTarget = computed(() => ['assign', 'status', 'label_add', 'label_remove', 'sprint'].includes(action.value));
const ready = computed(() => selected.value.length > 0 && action.value && (!needsTarget.value || target.value !== '' || action.value === 'assign'));
const run = () => {
    if (action.value === 'delete' && !confirmDelete.value) {
        confirmDelete.value = true;
        return;
    }
    confirmDelete.value = false;
    working.value = true;
    router.post(
        route('projects.tasks.bulk', props.project.id),
        {
            action: action.value,
            ids: selected.value,
            assignee_id: action.value === 'assign' && target.value !== '' && target.value !== 'none' ? target.value : null,
            status_id: action.value === 'status' ? target.value : null,
            label_id: action.value.startsWith('label_') ? target.value : null,
            sprint_id: action.value === 'sprint' && target.value !== 'none' ? target.value : null,
            close_children: closeChildren.value,
            with_children: action.value === 'delete',
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                action.value = target.value = '';
            },
            onFinish: () => (working.value = false),
        },
    );
};
const changeAction = () => {
    target.value = '';
    closeChildren.value = false;
};

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const create = () => window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id } }));
</script>

<template>
    <Head :title="`${project.key} · List`" />

    <AppLayout>
        <ProjectHeader :project="project" active="list">
            <template #actions>
                <Btn v-if="canCreate && !project.is_archived" @click="create"><PlusIcon class="h-4 w-4" /> Create</Btn>
            </template>
        </ProjectHeader>

        <TaskFilterBar mode="list" :filters="filters" :options="options" :saved-filters="savedFilters" :project-id="project.id" @apply="applyFilters" />

        <!-- bulk action bar -->
        <div v-if="canBulk && selected.length" class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm">
            <strong class="text-indigo-800">{{ selected.length }} selected</strong>
            <select v-model="action" aria-label="Bulk action" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500" @change="changeAction">
                <option value="">Choose an action…</option>
                <option value="assign">Assign to…</option>
                <option value="status">Change status…</option>
                <option value="label_add">Add label…</option>
                <option value="label_remove">Remove label…</option>
                <option v-if="openSprints.length" value="sprint">Move to sprint…</option>
                <option value="delete">Delete</option>
            </select>
            <select v-if="action === 'assign'" v-model="target" aria-label="Assignee" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Choose a person…</option>
                <option value="none">Unassigned</option>
                <option v-for="p in bulk.assignees" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select v-if="action === 'status'" v-model="target" aria-label="Status" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Choose a status…</option>
                <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-if="action === 'sprint'" v-model="target" aria-label="Sprint" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Choose a sprint…</option>
                <option value="none">Back to the backlog</option>
                <option v-for="s in openSprints" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-if="action.startsWith('label_')" v-model="target" aria-label="Label" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Choose a label…</option>
                <option v-for="l in options.labels" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
            <label v-if="action === 'status'" class="flex items-center gap-1.5 text-gray-700"><input v-model="closeChildren" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Close open sub-tasks too</label>
            <Btn size="sm" :disabled="!ready" :loading="working" :variant="action === 'delete' ? 'danger' : 'primary'" @click="run">Apply</Btn>
            <button type="button" class="ml-auto text-gray-500 underline hover:text-gray-800" @click="selected = []">Clear selection</button>
        </div>

        <div v-if="failed.length" role="alert" class="mb-3 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-900">
            <p class="font-medium">These tasks were skipped:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="f in failed" :key="f.key"><span class="font-mono">{{ f.key }}</span> — {{ f.reason }}</li>
            </ul>
        </div>

        <EmptyState v-if="!rows.length" :title="Object.keys(filters).length ? 'No tasks match these filters' : 'No tasks yet'" :description="Object.keys(filters).length ? 'Try removing a filter.' : 'Create the first task of this project.'" />

        <template v-else>
            <div class="mb-2 flex items-center justify-between text-sm text-gray-500">
                <span>{{ tasks.total }} task{{ tasks.total === 1 ? '' : 's' }}</span>
                <div ref="picker" class="relative">
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50" :aria-expanded="pickerOpen" @click="pickerOpen = !pickerOpen">
                        <ViewColumnsIcon class="h-4 w-4" /> Columns
                    </button>
                    <div v-if="pickerOpen" class="absolute right-0 z-30 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                        <label v-for="c in pickable" :key="c.key" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-gray-50">
                            <input type="checkbox" :checked="chosen.includes(c.key)" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="toggleColumn(c.key)" /> {{ c.label }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th v-if="canBulk" class="w-10 px-3 py-2"><input type="checkbox" :checked="allOn" aria-label="Select all on this page" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="toggleAll" /></th>
                            <th v-for="c in visible" :key="c.key" class="whitespace-nowrap px-3 py-2" :aria-sort="sort === c.sort ? (dir === 'asc' ? 'ascending' : 'descending') : undefined">
                                <button v-if="c.sort" type="button" class="inline-flex items-center gap-1 uppercase hover:text-gray-900" @click="sortBy(c.sort)">
                                    {{ c.label }}
                                    <component :is="dir === 'asc' ? ArrowUpIcon : ArrowDownIcon" v-if="sort === c.sort" class="h-3 w-3" />
                                </button>
                                <span v-else>{{ c.label }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in rows" :key="t.id" :data-task-key="t.key" :class="isSelected(t.key) ? 'bg-violet-50 ring-1 ring-inset ring-violet-300' : selected.includes(t.id) ? 'bg-indigo-50/50' : 'hover:bg-gray-50'">
                            <td v-if="canBulk" class="px-3 py-2"><input v-model="selected" type="checkbox" :value="t.id" :aria-label="`Select ${t.key}`" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /></td>
                            <td v-for="c in visible" :key="c.key" class="px-3 py-2 align-middle" :class="c.key === 'title' ? 'max-w-md' : 'whitespace-nowrap'">
                                <TaskLink v-if="c.key === 'key'" :task-key="t.key" class="font-mono font-semibold text-indigo-700 hover:underline">{{ t.key }}</TaskLink>
                                <InlineField v-else-if="c.key === 'title'" :model-value="t.title" type="text" :can="t.can.update" :label="`Title of ${t.key}`" :save="(v) => patchTask(t.key, { title: v })" :task-key="t.key" field="title" width-class="w-full max-w-md">
                                    <template #display><span class="block truncate text-gray-900" :title="t.title">{{ t.title }}</span></template>
                                </InlineField>
                                <TaskBits v-else-if="c.key === 'type'" kind="type" :value="t.type" />
                                <InlineField v-else-if="c.key === 'status'" :model-value="t.status?.id" type="select" :options="statusChoices" :can="t.can.status" :label="`Status of ${t.key}`" :save="(v) => moveTask(t.key, v)" :task-key="t.key" field="status">
                                    <template #display><TaskBits kind="status" :value="t.status" /></template>
                                </InlineField>
                                <span v-else-if="c.key === 'sprint'" class="text-gray-600">{{ t.sprint?.name ?? '—' }}</span>
                                <InlineField v-else-if="c.key === 'priority'" :model-value="t.priority" type="select" :options="priorityChoices" :can="t.can.update" :label="`Priority of ${t.key}`" :save="(v) => patchTask(t.key, { priority: v })" :task-key="t.key" field="priority">
                                    <template #display><TaskBits kind="priority" :value="t.priority" /></template>
                                </InlineField>
                                <InlineField v-else-if="c.key === 'assignee'" :model-value="t.assignee?.id ?? null" type="select" :options="assigneeChoices(t)" :can="t.can.assign" :label="`Assignee of ${t.key}`" :save="(v) => assignTask(t.key, v)" :task-key="t.key" field="assignee">
                                    <template #display>
                                        <span class="inline-flex items-center gap-2">
                                            <template v-if="t.assignee"><Avatar :name="t.assignee.name" size="sm" /> {{ t.assignee.name }}</template>
                                            <span v-else class="text-gray-400">Unassigned</span>
                                        </span>
                                    </template>
                                </InlineField>
                                <span v-else-if="c.key === 'reporter'">{{ t.reporter.name }}</span>
                                <span v-else-if="c.key === 'labels'" class="flex gap-1">
                                    <span v-for="l in t.labels" :key="l.id" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
                                </span>
                                <InlineField v-else-if="c.key === 'story_points'" :model-value="t.story_points" type="number" min="0" step="0.5" :can="t.can.update" :label="`Story points of ${t.key}`" :save="(v) => patchTask(t.key, { story_points: v })" :task-key="t.key" field="points">
                                    <template #display><span>{{ t.story_points ?? '—' }}</span></template>
                                </InlineField>
                                <InlineField v-else-if="c.key === 'due_date'" :model-value="t.due_date" type="date" :can="t.can.update" :label="`Due date of ${t.key}`" :save="(v) => patchTask(t.key, { due_date: v })" :task-key="t.key" field="due">
                                    <template #display><span :class="t.overdue ? 'font-medium text-red-600' : ''">{{ fmt(t.due_date) }}</span></template>
                                </InlineField>
                                <span v-else-if="c.key === 'created_at'">{{ fmt(t.created_at) }}</span>
                                <span v-else-if="c.key === 'updated_at'">{{ fmt(t.updated_at) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="canCreate && !project.is_archived" class="mt-3 max-w-md"><QuickAdd :project-id="project.id" /></div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <Pagination :links="tasks.links" />
                <label class="flex items-center gap-2 text-sm text-gray-500">
                    Per page
                    <select :value="perPage" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500" @change="visit({ per_page: $event.target.value, page: undefined })">
                        <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                    </select>
                </label>
            </div>
        </template>

        <ConfirmDialog
            :show="confirmDelete"
            title="Delete the selected tasks?"
            :message="`${selected.length} task(s) and their sub-tasks will be deleted. This can be undone by an administrator.`"
            confirm-text="Delete"
            danger
            :loading="working"
            @confirm="run"
            @cancel="confirmDelete = false"
        />
    </AppLayout>
</template>
