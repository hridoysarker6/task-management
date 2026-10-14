<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import TaskPicker from '@/Components/Tasks/TaskPicker.vue';
import Btn from '@/Components/UI/Btn.vue';
import RichTextEditor from '@/Components/UI/RichTextEditor.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import { parseDuration } from '@/lib/duration';
import axios from 'axios';
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// The global "Create" dialog. Open it from anywhere with:
//   window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId, parent, type } }))
const show = ref(false);
const loading = ref(false);
const options = ref(null); // from tasks.create-options
const parent = ref(null); // picked parent task { id, key, title }
const estimate = ref('');
const estimateError = ref(null);

const form = useForm({
    project_id: null,
    type: 'task',
    title: '',
    description: '',
    priority: 3,
    assignee_id: null,
    parent_id: null,
    labels: [],
    story_points: '',
    original_estimate_min: null,
    start_date: '',
    due_date: '',
    files: [],
});

const load = async (projectId = null) => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('tasks.create-options'), { params: { project_id: projectId || undefined } });
        options.value = data;
        // One project to choose from? Pick it for them.
        const id = projectId ?? (data.projects.length === 1 ? data.projects[0].id : null);
        if (id && !data.project) return load(id);
        form.project_id = data.project?.id ?? null;
    } finally {
        loading.value = false;
    }
};

const open = async (event) => {
    const d = event?.detail ?? {};
    form.reset();
    form.clearErrors();
    parent.value = null;
    estimate.value = '';
    estimateError.value = null;
    if (d.type) form.type = d.type;
    show.value = true;
    await load(d.projectId ?? null);
    if (d.parent) {
        parent.value = d.parent;
        form.parent_id = d.parent.id;
    }
};
onMounted(() => window.addEventListener('create-task', open));
onBeforeUnmount(() => window.removeEventListener('create-task', open));

const changeProject = (id) => {
    form.labels = [];
    form.assignee_id = null;
    parent.value = null;
    form.parent_id = null;
    load(id);
};

const project = computed(() => options.value?.project);
const projectOptions = computed(() => [{ value: null, label: 'Choose a project…' }, ...(options.value?.projects ?? []).map((p) => ({ value: p.id, label: `${p.key} · ${p.name}` }))]);
const typeOptions = computed(() => (options.value?.types ?? []).map((t) => ({ value: t, label: { epic: 'Epic', story: 'Story', task: 'Task', bug: 'Bug', subtask: 'Sub-task' }[t] })));
const priorityOptions = computed(() => options.value?.priorities ?? []);
const assigneeOptions = computed(() => [{ value: null, label: 'Unassigned' }, ...(project.value?.assignees ?? []).map((a) => ({ value: a.id, label: a.name }))]);

// Which kinds of task can be the parent depends on the type being created.
const parentTypes = computed(() => (form.type === 'subtask' ? 'story,task,bug' : ['story', 'task', 'bug'].includes(form.type) ? 'epic' : null));
const needsParent = computed(() => form.type === 'subtask');
// A type change can make the chosen parent invalid, so start again.
const onType = (t) => {
    form.type = t;
    parent.value = null;
    form.parent_id = null;
};
const pickParent = (t) => {
    parent.value = t;
    form.parent_id = t.id;
};

const toggleLabel = (id) => {
    const i = form.labels.indexOf(id);
    i === -1 ? form.labels.push(id) : form.labels.splice(i, 1);
};

const onFiles = (e) => (form.files = Array.from(e.target.files ?? []));

const submit = () => {
    estimateError.value = null;
    const minutes = parseDuration(estimate.value);
    if (Number.isNaN(minutes)) {
        estimateError.value = 'Use a form like 1h 30m, 90m or 2d.';
        return;
    }
    form
        .transform((d) => ({
            ...d,
            type: d.type,
            story_points: d.story_points === '' ? null : d.story_points,
            original_estimate_min: minutes,
            start_date: d.start_date || null,
            due_date: d.due_date || null,
            files: d.files.length ? d.files : undefined,
        }))
        .post(route('tasks.store'), { forceFormData: true, onSuccess: () => (show.value = false) });
};

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="show = false">
        <form class="max-h-[85vh] overflow-y-auto p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-gray-900">Create task</h2>

            <p v-if="loading && !options" class="mt-6 text-sm text-gray-500">Loading…</p>

            <div v-else-if="options && !options.projects.length" class="mt-4 rounded-lg bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                You are not a member of any project where you can add tasks yet. Ask a project manager to add you.
            </div>

            <div v-else-if="options" class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700" for="ct-project">Project</label>
                        <SelectInput id="ct-project" :model-value="form.project_id" :options="projectOptions" class="mt-1" @update:model-value="changeProject" />
                        <InputError class="mt-1" :message="form.errors.project_id" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="ct-type">Type</label>
                        <SelectInput id="ct-type" :model-value="form.type" :options="typeOptions" class="mt-1" @update:model-value="onType" />
                        <InputError class="mt-1" :message="form.errors.type" />
                    </div>
                </div>

                <p v-if="project?.triage" class="rounded-lg bg-purple-50 px-3 py-2 text-sm text-purple-800">
                    This goes to the project's triage queue first. The team reviews it, then adds it to the backlog.
                </p>

                <template v-if="project">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="ct-title">Title</label>
                        <input id="ct-title" v-model="form.title" type="text" required maxlength="255" autofocus :class="input" />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-700">Description</span>
                        <RichTextEditor v-model="form.description" class="mt-1" placeholder="What needs to be done, and why?" />
                        <InputError class="mt-1" :message="form.errors.description" />
                    </div>

                    <div v-if="parentTypes" class="rounded-lg border border-gray-200 p-3">
                        <label class="block text-sm font-medium text-gray-700">{{ needsParent ? 'Parent task (required)' : 'Epic (optional)' }}</label>
                        <div v-if="parent" class="mt-1 flex items-center gap-2 text-sm">
                            <span class="font-mono text-xs font-semibold text-indigo-700">{{ parent.key }}</span> <span class="truncate">{{ parent.title }}</span>
                            <button type="button" class="ml-auto text-xs text-gray-500 underline" @click="parent = null; form.parent_id = null">Change</button>
                        </div>
                        <TaskPicker v-else class="mt-1" :project-id="project.id" :types="parentTypes" @pick="pickParent" />
                        <InputError class="mt-1" :message="form.errors.parent_id" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="ct-priority">Priority</label>
                            <SelectInput id="ct-priority" v-model="form.priority" :options="priorityOptions" class="mt-1" />
                        </div>
                        <div v-if="project.assignees.length">
                            <label class="block text-sm font-medium text-gray-700" for="ct-assignee">Assignee</label>
                            <SelectInput id="ct-assignee" v-model="form.assignee_id" :options="assigneeOptions" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.assignee_id" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="ct-start">Start date</label>
                            <input id="ct-start" v-model="form.start_date" type="date" :class="input" />
                            <InputError class="mt-1" :message="form.errors.start_date" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="ct-due">Due date</label>
                            <input id="ct-due" v-model="form.due_date" type="date" :class="input" />
                            <InputError class="mt-1" :message="form.errors.due_date" />
                        </div>
                        <template v-if="options.effort">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="ct-points">Story points</label>
                                <input id="ct-points" v-model="form.story_points" type="number" min="0" step="0.5" :class="input" />
                                <InputError class="mt-1" :message="form.errors.story_points" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="ct-estimate">Original estimate</label>
                                <input id="ct-estimate" v-model="estimate" type="text" placeholder="e.g. 1h 30m" :class="input" />
                                <InputError class="mt-1" :message="estimateError || form.errors.original_estimate_min" />
                            </div>
                        </template>
                    </div>

                    <div v-if="project.labels.length">
                        <span class="block text-sm font-medium text-gray-700">Labels</span>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <button
                                v-for="l in project.labels"
                                :key="l.id"
                                type="button"
                                :aria-pressed="form.labels.includes(l.id)"
                                :class="['rounded-full border px-2.5 py-0.5 text-xs font-medium', form.labels.includes(l.id) ? 'border-transparent text-white' : 'border-gray-300 text-gray-600']"
                                :style="form.labels.includes(l.id) ? { backgroundColor: l.color } : {}"
                                @click="toggleLabel(l.id)"
                            >
                                {{ l.name }}
                            </button>
                        </div>
                        <InputError class="mt-1" :message="form.errors.labels" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="ct-files">Attachments</label>
                        <input
                            id="ct-files"
                            type="file"
                            multiple
                            class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                            @change="onFiles"
                        />
                        <p class="mt-1 text-xs text-gray-500">Up to {{ options.maxFileMb }} MB each: {{ options.extensions.join(', ') }}.</p>
                        <InputError class="mt-1" :message="form.errors.files || form.errors['files.0']" />
                    </div>
                </template>

                <InputError :message="form.errors.project" />
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <Btn variant="secondary" @click="show = false">Cancel</Btn>
                <Btn type="submit" :disabled="!project" :loading="form.processing">Create</Btn>
            </div>
        </form>
    </Modal>
</template>
