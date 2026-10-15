<script setup>
import InputError from '@/Components/InputError.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Btn from '@/Components/UI/Btn.vue';
import MultiSelect from '@/Components/UI/MultiSelect.vue';
import { BookmarkIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * The filter bar shared by the board, the list and the backlog. It keeps its own copy of the filters, tells the page
 * whenever they change (`apply`), and manages saved filters. `mode` decides which controls are shown.
 */
const props = defineProps({
    mode: { type: String, required: true }, // board | list | backlog
    filters: { type: Object, required: true },
    options: { type: Object, required: true }, // { statuses, people, labels, types, priorities, meId }
    savedFilters: { type: Array, default: () => [] },
    projectId: { type: Number, required: true },
});
const emit = defineEmits(['apply']);

const blank = () => ({ q: '', status: [], assignee: [], reporter: [], type: [], priority: [], label: [], sprint: [], mine: false, overdue: false, created_from: '', created_to: '', due_from: '', due_to: '' });
const draft = reactive({ ...blank(), ...props.filters });
watch(() => props.filters, (f) => Object.assign(draft, blank(), f));

// What goes in the URL: only filters that are actually set.
const clean = () => {
    const out = {};
    for (const [k, v] of Object.entries(draft)) {
        if (Array.isArray(v) ? v.length : v) out[k] = v;
    }
    return out;
};
const apply = () => emit('apply', clean());

let timer;
const onSearch = () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 300);
};
onBeforeUnmount(() => clearTimeout(timer));

const set = (key, value) => {
    draft[key] = value;
    apply();
};
const clear = () => {
    Object.assign(draft, blank());
    apply();
};
const active = computed(() => Object.keys(clean()).length);

// Assignee avatars (board): clicking one adds or removes that person from the filter.
const toggleAssignee = (id) => {
    const i = draft.assignee.indexOf(id);
    i === -1 ? draft.assignee.push(id) : draft.assignee.splice(i, 1);
    apply();
};
const showAvatars = computed(() => props.mode === 'board' && props.options.people.length > 0 && props.options.people.length <= 12);

const opt = (items, label = 'name') => items.map((i) => ({ value: i.id, label: i[label], color: i.color }));
const typeOptions = computed(() => props.options.types.map((t) => ({ value: t, label: { epic: 'Epic', story: 'Story', task: 'Task', bug: 'Bug', subtask: 'Sub-task' }[t] })));
const priorityOptions = computed(() => props.options.priorities.map((p) => ({ value: p.value, label: p.label })));
const assigneeOptions = computed(() => [{ value: 'none', label: 'Unassigned' }, ...opt(props.options.people)]);
const advanced = computed(() => props.mode === 'list');
const sprintOptions = computed(() => [{ value: 'none', label: 'No sprint' }, ...props.options.sprints.map((s) => ({ value: s.id, label: s.name }))]);

// --- saved filters ---------------------------------------------------------------------------
const menu = ref(false);
const root = ref(null);
const onDocClick = (e) => {
    if (root.value && !root.value.contains(e.target)) menu.value = false;
};
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));

const view = computed(() => (props.mode === 'board' ? 'board' : 'list'));
const useSaved = (f) => {
    Object.assign(draft, blank(), f.filters);
    menu.value = false;
    apply();
};

const form = useForm({ project_id: props.projectId, name: '', view: view.value, filters: {}, is_shared: false });
const save = () => {
    form
        .transform((d) => ({ ...d, filters: clean() }))
        .post(route('saved-filters.store'), { preserveScroll: true, onSuccess: () => form.reset('name', 'is_shared') });
};
const remove = (f) => router.delete(route('saved-filters.destroy', f.id), { preserveScroll: true });
</script>

<template>
    <div class="mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model="draft.q"
                type="search"
                placeholder="Search key or title…"
                aria-label="Search tasks"
                class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                @input="onSearch"
            />

            <div v-if="showAvatars" class="flex items-center -space-x-1.5" role="group" aria-label="Filter by assignee">
                <button
                    v-for="p in options.people"
                    :key="p.id"
                    type="button"
                    :title="p.name"
                    :aria-pressed="draft.assignee.includes(p.id)"
                    :class="['rounded-full ring-2 transition', draft.assignee.includes(p.id) ? 'z-10 ring-indigo-500' : 'ring-white opacity-70 hover:opacity-100']"
                    @click="toggleAssignee(p.id)"
                >
                    <Avatar :name="p.name" size="sm" />
                </button>
            </div>

            <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm" :class="draft.mine ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'">
                <input type="checkbox" class="sr-only" :checked="draft.mine" @change="set('mine', $event.target.checked)" /> Only my issues
            </label>

            <MultiSelect v-if="advanced" label="Status" :model-value="draft.status" :options="opt(options.statuses)" @update:model-value="set('status', $event)" />
            <MultiSelect v-if="advanced || mode === 'backlog'" label="Assignee" :model-value="draft.assignee" :options="assigneeOptions" @update:model-value="set('assignee', $event)" />
            <MultiSelect v-if="advanced" label="Reporter" :model-value="draft.reporter" :options="opt(options.people)" @update:model-value="set('reporter', $event)" />
            <MultiSelect label="Type" :model-value="draft.type" :options="typeOptions" @update:model-value="set('type', $event)" />
            <MultiSelect label="Priority" :model-value="draft.priority" :options="priorityOptions" @update:model-value="set('priority', $event)" />
            <MultiSelect v-if="advanced && options.sprints?.length" label="Sprint" :model-value="draft.sprint" :options="sprintOptions" @update:model-value="set('sprint', $event)" />
            <MultiSelect v-if="options.labels.length" label="Label" :model-value="draft.label" :options="opt(options.labels)" @update:model-value="set('label', $event)" />

            <label v-if="advanced" class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm" :class="draft.overdue ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'">
                <input type="checkbox" class="sr-only" :checked="draft.overdue" @change="set('overdue', $event.target.checked)" /> Overdue
            </label>

            <button v-if="active" type="button" class="text-sm text-gray-500 underline hover:text-gray-800" @click="clear">Clear filters</button>

            <!-- Saved filters -->
            <div v-if="mode !== 'backlog'" ref="root" class="relative ml-auto">
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50" :aria-expanded="menu" @click="menu = !menu">
                    <BookmarkIcon class="h-4 w-4" /> Saved filters<span v-if="savedFilters.length" class="rounded-full bg-gray-200 px-1.5 text-xs">{{ savedFilters.length }}</span>
                </button>
                <div v-if="menu" class="absolute right-0 z-30 mt-1 w-72 rounded-lg border border-gray-200 bg-white p-2 text-sm shadow-lg">
                    <ul v-if="savedFilters.length" class="max-h-56 space-y-0.5 overflow-auto">
                        <li v-for="f in savedFilters" :key="f.id" class="flex items-center gap-1 rounded hover:bg-gray-50">
                            <button type="button" class="min-w-0 flex-1 px-2 py-1.5 text-left" @click="useSaved(f)">
                                <span class="block truncate font-medium text-gray-800">{{ f.name }}</span>
                                <span class="block text-xs text-gray-400">{{ f.is_shared ? (f.mine ? 'Shared by you' : `Shared by ${f.owner}`) : 'Private' }}</span>
                            </button>
                            <button v-if="f.mine" type="button" class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Delete ${f.name}`" @click="remove(f)"><TrashIcon class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <p v-else class="px-2 py-1.5 text-gray-400">No saved filters yet.</p>

                    <form class="mt-2 space-y-2 border-t border-gray-100 pt-2" @submit.prevent="save">
                        <label class="block text-xs font-medium text-gray-500" for="sf-name">Save the current filters as</label>
                        <input id="sf-name" v-model="form.name" type="text" maxlength="100" required placeholder="e.g. My open bugs" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <label class="flex items-center gap-2 text-xs text-gray-600"><input v-model="form.is_shared" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Share with everyone on the project</label>
                        <InputError :message="form.errors.name || form.errors.filters" />
                        <Btn type="submit" size="sm" :disabled="!active" :loading="form.processing" class="w-full">Save filter</Btn>
                        <p v-if="!active" class="text-xs text-gray-400">Set at least one filter first.</p>
                    </form>
                </div>
            </div>
        </div>

        <div v-if="advanced" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-gray-600">
            <div class="flex items-center gap-2">
                <span>Created</span>
                <input type="date" :value="draft.created_from" aria-label="Created from" class="rounded-lg border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="set('created_from', $event.target.value)" />
                <span>to</span>
                <input type="date" :value="draft.created_to" aria-label="Created to" class="rounded-lg border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="set('created_to', $event.target.value)" />
            </div>
            <div class="flex items-center gap-2">
                <span>Due</span>
                <input type="date" :value="draft.due_from" aria-label="Due from" class="rounded-lg border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="set('due_from', $event.target.value)" />
                <span>to</span>
                <input type="date" :value="draft.due_to" aria-label="Due to" class="rounded-lg border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="set('due_to', $event.target.value)" />
            </div>
        </div>
    </div>
</template>
