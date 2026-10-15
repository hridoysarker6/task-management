<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PencilSquareIcon, PlayIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({
    project: { type: Object, required: true },
    sprints: { type: Array, required: true },
    backlog: { type: Array, required: true },
    backlogTotal: { type: Number, default: 0 },
    completed: { type: Array, default: () => [] },
    velocity: { type: Number, default: null },
    can: { type: Object, required: true },
    canCreateTask: Boolean,
});

const page = usePage();
const sprintError = computed(() => page.props.errors?.sprint ?? page.props.errors?.end_date ?? page.props.errors?.next_sprint_id ?? '');
const readOnly = computed(() => props.project.is_archived);
const canPlan = computed(() => props.can.edit && !readOnly.value);

// --- lists (one per sprint plus the backlog) ----------------------------------------------------
const lists = ref({});
watch(
    () => [props.sprints, props.backlog],
    () => {
        const out = { backlog: [...props.backlog] };
        for (const s of props.sprints) out[s.id] = [...s.tasks];
        lists.value = out;
    },
    { immediate: true },
);

const dragError = ref('');
const post = async (url, body) => {
    dragError.value = '';
    try {
        await axios.post(url, body);
    } catch (e) {
        const errors = e.response?.data?.errors ?? {};
        dragError.value = e.response?.status === 403 ? 'You are not allowed to plan this sprint.' : (Object.values(errors)[0]?.[0] ?? 'That could not be done.');
    }
    router.reload({ preserveScroll: true });
};

// Dropping on another list changes the sprint; dropping inside one only re-orders it.
const onChange = (listKey, evt) => {
    const hit = evt.added ?? evt.moved;
    if (!hit) return;
    const list = lists.value[listKey];
    const i = hit.newIndex;
    const neighbours = { prev_id: list[i - 1]?.id ?? null, next_id: list[i + 1]?.id ?? null };
    if (evt.added) post(route('tasks.sprint', hit.element.key), { sprint_id: listKey === 'backlog' ? null : listKey, ...neighbours });
    else post(route('tasks.rank', hit.element.key), neighbours);
};

// --- sprint form ---------------------------------------------------------------------------------
const today = () => new Date().toISOString().slice(0, 10);
const inDays = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);
const editing = ref(null); // a sprint, or 'new'
const form = useForm({ name: '', goal: '', start_date: today(), end_date: inDays(13) });

const openForm = (sprint = null) => {
    editing.value = sprint ?? 'new';
    form.clearErrors();
    if (sprint) form.defaults({ name: sprint.name, goal: sprint.goal ?? '', start_date: sprint.start_date, end_date: sprint.end_date }).reset();
    else form.defaults({ name: `Sprint ${props.sprints.length + props.completed.length + 1}`, goal: '', start_date: today(), end_date: inDays(13) }).reset();
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post(route('projects.sprints.store', props.project.id), options) : form.put(route('sprints.update', editing.value.id), options);
};

// --- start / complete / delete -------------------------------------------------------------------
const start = (s) => router.post(route('sprints.start', s.id), {}, { preserveScroll: true });

const completing = ref(null);
const destination = ref('');
const planned = computed(() => props.sprints.filter((s) => s.status === 'planned'));
const unfinished = computed(() => (completing.value ? completing.value.task_count - completing.value.done_count : 0));
const askComplete = (s) => {
    completing.value = s;
    destination.value = '';
};
const complete = () =>
    router.post(route('sprints.complete', completing.value.id), { next_sprint_id: destination.value || null }, { preserveScroll: true, onFinish: () => (completing.value = null) });

const deleting = ref(null);
const remove = () => router.delete(route('sprints.destroy', deleting.value.id), { preserveScroll: true, onFinish: () => (deleting.value = null) });

const fmt = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const overVelocity = (s) => props.velocity !== null && s.points > props.velocity;
const createTask = () => window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id } }));
</script>

<template>
    <Head :title="`${project.key} · Sprints`" />

    <AppLayout>
        <ProjectHeader :project="project" active="sprints">
            <template #actions>
                <Btn v-if="canCreateTask && !readOnly" variant="secondary" @click="createTask"><PlusIcon class="h-4 w-4" /> Create task</Btn>
                <Btn v-if="can.create && !readOnly" @click="openForm()"><PlusIcon class="h-4 w-4" /> New sprint</Btn>
            </template>
        </ProjectHeader>

        <p v-if="sprintError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ sprintError }}</p>
        <p v-if="dragError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ dragError }}</p>
        <p v-if="velocity !== null" class="mb-3 text-sm text-gray-500">Average velocity of the last sprints: <strong class="text-gray-800">{{ velocity }} points</strong>. Plan around it.</p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <!-- sprints -->
            <div class="space-y-4">
                <EmptyState v-if="!sprints.length" title="No sprint yet" description="Create a sprint, then drag tasks into it from the backlog." />

                <section v-for="s in sprints" :key="s.id" class="rounded-xl border bg-white" :class="s.status === 'active' ? 'border-indigo-300' : 'border-gray-200'">
                    <header class="border-b border-gray-100 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-gray-900">{{ s.name }}</h2>
                            <Badge :color="s.status === 'active' ? 'green' : 'gray'">{{ s.status === 'active' ? 'Active' : 'Planned' }}</Badge>
                            <span class="text-xs text-gray-500">{{ fmt(s.start_date) }} – {{ fmt(s.end_date) }}</span>
                            <div class="ml-auto flex items-center gap-1">
                                <Btn v-if="s.status === 'planned' && can.start && !readOnly" size="sm" @click="start(s)"><PlayIcon class="h-4 w-4" /> Start</Btn>
                                <Btn v-if="s.status === 'active' && can.complete && !readOnly" size="sm" @click="askComplete(s)">Complete sprint</Btn>
                                <button v-if="can.edit && !readOnly" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" :aria-label="`Edit ${s.name}`" @click="openForm(s)"><PencilSquareIcon class="h-4 w-4" /></button>
                                <button v-if="s.status === 'planned' && can.delete && !readOnly" class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Delete ${s.name}`" @click="deleting = s"><TrashIcon class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <p v-if="s.goal" class="mt-1 text-sm text-gray-600">{{ s.goal }}</p>

                        <p class="mt-2 text-xs text-gray-500">
                            {{ s.task_count }} task{{ s.task_count === 1 ? '' : 's' }}
                            · <span :class="overVelocity(s) ? 'font-semibold text-orange-600' : ''">{{ s.points }} points</span><template v-if="s.status === 'active'"> ({{ s.done_points }} done<template v-if="s.committed_points !== null">, {{ s.committed_points }} committed</template>)</template>
                            · {{ s.hours }} h remaining
                            <span v-if="overVelocity(s)" class="text-orange-600"> — more than the usual {{ velocity }}</span>
                        </p>

                        <ul v-if="s.people.length" class="mt-2 flex flex-wrap gap-2" aria-label="Load per person">
                            <li v-for="p in s.people" :key="p.id ?? 'none'" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                <Avatar v-if="p.id" :name="p.name" size="sm" />
                                {{ p.name }}: {{ p.tasks }} · {{ p.points }} pts · {{ p.hours }} h
                            </li>
                        </ul>
                    </header>

                    <draggable
                        v-model="lists[s.id]"
                        group="plan"
                        item-key="id"
                        :disabled="!canPlan"
                        ghost-class="opacity-40"
                        class="min-h-[3.5rem] divide-y divide-gray-100"
                        @change="onChange(s.id, $event)"
                    >
                        <template #item="{ element: t }">
                            <div class="flex cursor-grab items-center gap-2 px-4 py-2 text-sm active:cursor-grabbing" :data-task="t.key">
                                <TaskBits kind="type" :value="t.type" />
                                <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline" draggable="false">{{ t.key }}</Link>
                                <span class="min-w-0 flex-1 truncate text-gray-900">{{ t.title }}</span>
                                <TaskBits kind="status" :value="t.status" />
                                <span v-if="t.story_points !== null" class="rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600" title="Story points">{{ t.story_points }}</span>
                                <Avatar v-if="t.assignee" :name="t.assignee.name" size="sm" />
                            </div>
                        </template>
                        <template #footer>
                            <p v-if="!lists[s.id]?.length" class="px-4 py-3 text-sm text-gray-400">{{ canPlan ? 'Drag tasks here from the backlog.' : 'No tasks in this sprint.' }}</p>
                        </template>
                    </draggable>
                </section>

                <section v-if="completed.length" class="rounded-xl border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">Recently completed</h2>
                    <ul class="divide-y divide-gray-100 text-sm">
                        <li v-for="c in completed" :key="c.id" class="flex items-center gap-3 px-4 py-2">
                            <span class="font-medium text-gray-800">{{ c.name }}</span>
                            <span class="text-xs text-gray-400">ended {{ fmt(c.end_date) }}</span>
                            <span class="ml-auto text-gray-600">{{ c.completed_points }} of {{ c.committed_points }} points</span>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- backlog -->
            <section class="self-start rounded-xl border border-gray-200 bg-white">
                <header class="border-b border-gray-100 px-4 py-3">
                    <h2 class="font-semibold text-gray-900">Backlog <span class="text-sm font-normal text-gray-400">{{ backlogTotal }}</span></h2>
                    <p class="text-xs text-gray-500">Not started and not in a sprint, in priority order.</p>
                </header>
                <draggable v-model="lists.backlog" group="plan" item-key="id" :disabled="!canPlan" ghost-class="opacity-40" class="min-h-[4rem] divide-y divide-gray-100" @change="onChange('backlog', $event)">
                    <template #item="{ element: t }">
                        <div class="flex cursor-grab items-center gap-2 px-4 py-2 text-sm active:cursor-grabbing" :data-task="t.key">
                            <TaskBits kind="type" :value="t.type" />
                            <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline" draggable="false">{{ t.key }}</Link>
                            <span class="min-w-0 flex-1 truncate text-gray-900">{{ t.title }}</span>
                            <TaskBits kind="priority" :value="t.priority" />
                            <span v-if="t.story_points !== null" class="rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600" title="Story points">{{ t.story_points }}</span>
                        </div>
                    </template>
                    <template #footer>
                        <p v-if="!lists.backlog?.length" class="px-4 py-3 text-sm text-gray-400">Nothing waiting. New tasks appear here.</p>
                    </template>
                </draggable>
            </section>
        </div>

        <!-- create / edit -->
        <Modal :show="!!editing" max-width="md" @close="editing = null">
            <form class="space-y-4 p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-gray-900">{{ editing === 'new' ? 'New sprint' : 'Edit sprint' }}</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="sp-name">Name</label>
                    <input id="sp-name" v-model="form.name" type="text" required maxlength="100" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="sp-goal">Goal</label>
                    <textarea id="sp-goal" v-model="form.goal" rows="2" maxlength="2000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError class="mt-1" :message="form.errors.goal" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="sp-start">Starts</label>
                        <input id="sp-start" v-model="form.start_date" type="date" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="form.errors.start_date" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="sp-end">Ends</label>
                        <input id="sp-end" v-model="form.end_date" type="date" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="form.errors.end_date" />
                    </div>
                </div>
                <InputError :message="form.errors.sprint" />
                <div class="flex justify-end gap-2">
                    <Btn variant="secondary" @click="editing = null">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">Save</Btn>
                </div>
            </form>
        </Modal>

        <!-- complete -->
        <ConfirmDialog :show="!!completing" :title="`Complete ${completing?.name}?`" confirm-text="Complete sprint" @confirm="complete" @cancel="completing = null">
            <p class="mt-2 text-sm text-gray-600">{{ completing?.done_count }} of {{ completing?.task_count }} tasks are done.</p>
            <div v-if="unfinished" class="mt-3">
                <label class="block text-sm font-medium text-gray-700" for="sp-dest">What happens to the {{ unfinished }} unfinished task{{ unfinished === 1 ? '' : 's' }}?</label>
                <select id="sp-dest" v-model="destination" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Back to the backlog</option>
                    <option v-for="p in planned" :key="p.id" :value="p.id">Move to {{ p.name }}</option>
                </select>
            </div>
        </ConfirmDialog>

        <ConfirmDialog :show="!!deleting" title="Delete this sprint?" :message="deleting ? `“${deleting.name}” will be removed and its tasks go back to the backlog.` : ''" confirm-text="Delete" danger @confirm="remove" @cancel="deleting = null" />
    </AppLayout>
</template>
