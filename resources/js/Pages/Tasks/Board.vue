<script setup>
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import TaskCard from '@/Components/Tasks/TaskCard.vue';
import TaskFilterBar from '@/Components/Tasks/TaskFilterBar.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({
    project: { type: Object, required: true },
    columns: { type: Array, required: true },
    tasks: { type: Array, required: true },
    lanes: { type: Array, required: true },
    swimlane: { type: String, required: true },
    sprint: { type: Object, default: null },
    filters: { type: Object, required: true },
    truncated: Boolean,
    options: { type: Object, required: true },
    savedFilters: { type: Array, default: () => [] },
    canMove: Boolean,
    canCreate: Boolean,
    showEffort: Boolean,
});

// A "cell" is one status column inside one lane (with no swimlanes there is a single lane).
const laneRows = computed(() => (props.swimlane === 'none' ? [{ key: 'all', label: '', sub: null }] : props.lanes));
const laneOf = (t) => (props.swimlane === 'assignee' ? t.assignee_key : props.swimlane === 'epic' ? t.epic_key : 'all');
const cellKey = (lane, status) => `${lane}|${status}`;

const cells = ref({});
const build = () => {
    const out = {};
    for (const l of laneRows.value) for (const c of props.columns) out[cellKey(l.key, c.id)] = [];
    for (const t of props.tasks) {
        const k = cellKey(laneOf(t), t.status.id);
        if (out[k]) out[k].push(t);
    }
    cells.value = out;
};
watch(() => [props.tasks, props.columns, props.swimlane, props.lanes], build, { immediate: true });

const total = (statusId) => props.tasks.filter((t) => t.status.id === statusId).length;

// --- filters / swimlanes ---------------------------------------------------------------------
const visit = (filters, swimlane = props.swimlane) =>
    router.get(route('projects.board', props.project.id), { ...filters, ...(swimlane !== 'none' ? { swimlane } : {}) }, { preserveState: true, preserveScroll: true, replace: true });

// --- moving cards ----------------------------------------------------------------------------
const error = ref('');
const pending = ref(null); // a move waiting for "close the sub-tasks too?"
const busy = ref(false);

const send = async (payload, closeChildren = false) => {
    busy.value = true;
    error.value = '';
    try {
        await axios.post(route('tasks.move', payload.key), { status_id: payload.status_id, prev_id: payload.prev_id, next_id: payload.next_id, close_children: closeChildren });
        pending.value = null;
        router.reload({ preserveScroll: true });
    } catch (e) {
        const errors = e.response?.data?.errors ?? {};
        if (errors.open_children && !closeChildren) {
            pending.value = { ...payload, message: errors.open_children[0] };
        } else {
            pending.value = null;
            error.value = e.response?.status === 403 ? 'You are not allowed to move this task.' : (Object.values(errors)[0]?.[0] ?? e.response?.data?.message ?? 'The card could not be moved.');
            router.reload({ preserveScroll: true }); // put the card back
        }
    } finally {
        busy.value = false;
    }
};

const onChange = (statusId, laneKey, evt) => {
    const hit = evt.added ?? evt.moved;
    if (!hit) return;
    const list = cells.value[cellKey(laneKey, statusId)];
    const i = hit.newIndex;
    send({ key: hit.element.key, status_id: statusId, prev_id: list[i - 1]?.id ?? null, next_id: list[i + 1]?.id ?? null });
};
const cancelPending = () => {
    pending.value = null;
    router.reload({ preserveScroll: true });
};

const create = () => window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id } }));
const hasCards = computed(() => props.tasks.length > 0);
const filtered = computed(() => Object.keys(props.filters).length > 0);
</script>

<template>
    <Head :title="`${project.key} · Board`" />

    <AppLayout>
        <ProjectHeader :project="project" active="board">
            <template #actions>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    Group by
                    <select :value="swimlane" class="rounded-lg border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="visit(filters, $event.target.value)">
                        <option value="none">None</option>
                        <option value="assignee">Assignee</option>
                        <option value="epic">Epic</option>
                    </select>
                </label>
                <Btn v-if="canCreate && !project.is_archived" @click="create"><PlusIcon class="h-4 w-4" /> Create</Btn>
            </template>
        </ProjectHeader>

        <div v-if="project.uses_sprints" class="mb-4 rounded-lg border px-4 py-3 text-sm" :class="sprint ? 'border-indigo-200 bg-indigo-50' : 'border-gray-200 bg-white'">
            <template v-if="sprint">
                <strong class="text-indigo-900">{{ sprint.name }}</strong>
                <span class="ml-2 text-indigo-800">{{ sprint.days_left < 0 ? `${-sprint.days_left} day(s) overdue` : sprint.days_left === 0 ? 'ends today' : `${sprint.days_left} day(s) left` }}</span>
                <p v-if="sprint.goal" class="mt-1 text-indigo-800">Goal: {{ sprint.goal }}</p>
            </template>
            <template v-else>
                <span class="text-gray-700">No sprint is running, so the board is empty.</span>
                <Link v-if="project.show_sprints" :href="route('projects.sprints.index', project.id)" class="ml-2 font-medium text-indigo-600 hover:underline">Plan and start a sprint</Link>
            </template>
        </div>

        <TaskFilterBar mode="board" :filters="filters" :options="options" :saved-filters="savedFilters" :project-id="project.id" @apply="visit($event)" />

        <p v-if="error" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-if="truncated" class="mb-3 rounded-lg bg-yellow-50 px-3 py-2 text-sm text-yellow-800">Only the first 500 cards are shown. Use the filters to narrow the board down.</p>
        <p v-if="!canMove" class="mb-3 text-xs text-gray-400">You can view this board but not move cards.</p>

        <EmptyState v-if="!columns.length" title="No board columns" description="Every status of this project is hidden from the board. Turn one on under Workflow." />

        <template v-else>
            <EmptyState v-if="!hasCards && !filtered" title="Nothing on the board yet" description="Create the first task and it will appear in its status column." />

            <div class="overflow-x-auto pb-4">
                <div class="min-w-max">
                    <!-- column headers -->
                    <div class="sticky top-0 z-10 flex gap-3 bg-gray-50 pb-2">
                        <div v-for="c in columns" :key="c.id" class="w-72 shrink-0 rounded-lg bg-gray-100 px-3 py-2">
                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: c.color }" />
                                {{ c.name }}
                                <span class="ml-auto rounded-full bg-white px-2 py-0.5 text-gray-500">{{ total(c.id) }}</span>
                            </div>
                        </div>
                    </div>

                    <section v-for="lane in laneRows" :key="lane.key" class="mb-4">
                        <h2 v-if="swimlane !== 'none'" class="mb-2 text-sm font-semibold text-gray-700">
                            {{ lane.label }} <span v-if="lane.sub" class="font-mono text-xs text-purple-600">{{ lane.sub }}</span>
                        </h2>
                        <div class="flex gap-3">
                            <div v-for="c in columns" :key="c.id" class="w-72 shrink-0 rounded-lg bg-gray-100 p-2" :data-status="c.id">
                                <draggable
                                    v-model="cells[cellKey(lane.key, c.id)]"
                                    group="board"
                                    item-key="id"
                                    :disabled="!canMove || busy || project.is_archived"
                                    ghost-class="opacity-40"
                                    class="min-h-[4rem] space-y-2"
                                    @change="onChange(c.id, lane.key, $event)"
                                >
                                    <template #item="{ element }">
                                        <TaskCard :task="element" :show-effort="showEffort" />
                                    </template>
                                </draggable>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </template>

        <ConfirmDialog
            :show="!!pending"
            title="Close the sub-tasks too?"
            :message="pending?.message ?? ''"
            confirm-text="Close them all"
            :loading="busy"
            @confirm="send(pending, true)"
            @cancel="cancelPending"
        />
    </AppLayout>
</template>
