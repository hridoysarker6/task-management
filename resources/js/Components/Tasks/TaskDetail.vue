<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import MentionTextarea from '@/Components/Tasks/MentionTextarea.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import TaskPicker from '@/Components/Tasks/TaskPicker.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import RichTextEditor from '@/Components/UI/RichTextEditor.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import { formatDuration, parseDuration } from '@/lib/duration';
import { ArrowDownTrayIcon, EyeIcon, EyeSlashIcon, LinkIcon, PaperClipIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    project: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    parent: { type: Object, default: null },
    sprint: { type: Object, default: null },
    worklogs: { type: Array, default: () => [] },
    rollup: { type: Object, default: null },
    children: { type: Array, default: () => [] },
    progress: { type: Object, default: null },
    comments: { type: Array, default: () => [] },
    attachments: { type: Array, default: () => [] },
    links: { type: Array, default: () => [] },
    watchers: { type: Array, default: () => [] },
    isWatching: { type: Boolean, default: false },
    activities: { type: Array, default: () => [] },
    abilities: { type: Object, required: true },
    options: { type: Object, required: true },
    isInTriage: { type: Boolean, default: false },
    inPanel: { type: Boolean, default: false }, // shown in the side panel: no page title, tighter layout
});

const page = usePage();
const a = props.abilities;
const key = computed(() => props.task.key);
const patch = (data, onSuccess) => router.patch(route('tasks.update', key.value), data, { preserveScroll: true, onSuccess });

const fmtDate = (d) => (d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const fmtTime = (d) => new Date(d).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const fmtSize = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

// --- title & description ------------------------------------------------------------------
const editingTitle = ref(false);
const titleDraft = ref(props.task.title);
const saveTitle = () => {
    editingTitle.value = false;
    if (titleDraft.value.trim() && titleDraft.value !== props.task.title) patch({ title: titleDraft.value.trim() });
    else titleDraft.value = props.task.title;
};

const editingDescription = ref(false);
const descriptionDraft = ref(props.task.description ?? '');
watch(() => props.task.description, (d) => (descriptionDraft.value = d ?? ''));
const saveDescription = () => patch({ description: descriptionDraft.value }, () => (editingDescription.value = false));

// --- status -------------------------------------------------------------------------------
const statusOptions = computed(() =>
    props.options.statuses.map((s) => ({ value: s.id, label: s.allowed || s.id === props.task.status.id ? s.name : `${s.name} (not allowed)` })),
);
const statusReason = computed(() => props.options.statuses.find((s) => s.id === pendingStatus.value)?.reason);
const pendingStatus = ref(null);
const closeChildren = ref(false);
const openChildrenMessage = ref(null);
const statusError = ref(null);

const moveTo = (id, extra = {}) => {
    const target = props.options.statuses.find((s) => s.id === id);
    if (!target || id === props.task.status.id) return;
    statusError.value = null;
    if (!target.allowed) {
        statusError.value = target.reason;
        return;
    }
    router.post(route('tasks.status', key.value), { status_id: id, ...extra }, {
        preserveScroll: true,
        onError: (e) => {
            if (e.open_children) {
                // Closing a parent with open sub-tasks: ask before closing them too.
                pendingStatus.value = id;
                openChildrenMessage.value = e.open_children;
            } else {
                statusError.value = e.status_id ?? Object.values(e)[0];
            }
        },
        onSuccess: () => ((pendingStatus.value = null), (openChildrenMessage.value = null)),
    });
};

// --- assignee ---------------------------------------------------------------------------
const assigneeOptions = computed(() => {
    const list = props.options.assignees.map((p) => ({ value: p.id, label: p.name }));
    const current = props.task.assignee;
    if (current && !list.some((o) => o.value === current.id)) list.unshift({ value: current.id, label: current.name });
    return [{ value: null, label: 'Unassigned' }, ...list];
});
const assign = (id) => router.post(route('tasks.assign', key.value), { assignee_id: id }, { preserveScroll: true });
const myId = computed(() => props.options.assignees.find((p) => p.name === page.props.auth.user.name)?.id);

// --- other fields -------------------------------------------------------------------------
const typeOptions = computed(() => props.options.types.map((t) => ({ value: t, label: { epic: 'Epic', story: 'Story', task: 'Task', bug: 'Bug', subtask: 'Sub-task' }[t] })));
const priorityOptions = computed(() => props.options.priorities);

const estimateDrafts = ref({ original: formatDuration(props.task.original_estimate_min), remaining: formatDuration(props.task.remaining_estimate_min) });
watch(() => [props.task.original_estimate_min, props.task.remaining_estimate_min], ([o, r]) => (estimateDrafts.value = { original: formatDuration(o), remaining: formatDuration(r) }));
const estimateError = ref(null);
const saveEstimate = (field, draft) => {
    const minutes = parseDuration(draft);
    if (Number.isNaN(minutes)) {
        estimateError.value = 'Use a form like 1h 30m, 90m or 2d.';
        return;
    }
    estimateError.value = null;
    patch({ [field]: minutes });
};
const savePoints = (e) => patch({ story_points: e.target.value === '' ? null : e.target.value });

const toggleLabel = (id) => {
    const ids = props.task.labels.map((l) => l.id);
    patch({ labels: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id] });
};
const hasLabel = (id) => props.task.labels.some((l) => l.id === id);

const changingParent = ref(false);
const parentTypes = computed(() => (props.task.type === 'subtask' ? 'story,task,bug' : ['story', 'task', 'bug'].includes(props.task.type) ? 'epic' : null));
const setParent = (t) => patch({ parent_id: t?.id ?? null }, () => (changingParent.value = false));

// --- sub-tasks --------------------------------------------------------------------------
const addSubtask = () =>
    window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id, type: 'subtask', parent: { id: props.task.id, key: props.task.key, title: props.task.title } } }));
const addChild = () =>
    window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id, type: 'task', parent: { id: props.task.id, key: props.task.key, title: props.task.title } } }));

// --- watching ---------------------------------------------------------------------------
const toggleWatch = () => (props.isWatching ? router.delete(route('tasks.unwatch', key.value), { preserveScroll: true }) : router.post(route('tasks.watch', key.value), {}, { preserveScroll: true }));

// --- comments ---------------------------------------------------------------------------
const commentForm = useForm({ body: '', is_internal: !page.props.auth.user ? false : a.internal_comments });
const addComment = () => commentForm.post(route('tasks.comments.store', key.value), { preserveScroll: true, onSuccess: () => commentForm.reset('body') });

const editingComment = ref(null);
const editForm = useForm({ body: '', is_internal: false });
const startEdit = (c) => {
    editingComment.value = c.id;
    editForm.body = c.body;
    editForm.is_internal = c.is_internal;
    editForm.clearErrors();
};
const saveComment = (c) => editForm.put(route('comments.update', c.id), { preserveScroll: true, onSuccess: () => (editingComment.value = null) });
const deletingComment = ref(null);
const deleteComment = () => router.delete(route('comments.destroy', deletingComment.value.id), { preserveScroll: true, onFinish: () => (deletingComment.value = null) });

// A comment is plain text with mention tokens: split it so only plain text is ever rendered as text.
const segments = (body) => {
    const out = [];
    let last = 0;
    for (const m of body.matchAll(/@\[([^\]]{1,100})\]\(m:(\d+)\)/g)) {
        if (m.index > last) out.push({ text: body.slice(last, m.index) });
        out.push({ mention: m[1] });
        last = m.index + m[0].length;
    }
    if (last < body.length) out.push({ text: body.slice(last) });
    return out;
};

// --- attachments ------------------------------------------------------------------------
const uploadError = ref(null);
const upload = (e) => {
    const files = Array.from(e.target.files ?? []);
    if (!files.length) return;
    uploadError.value = null;
    router.post(route('tasks.attachments.store', key.value), { files }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errs) => (uploadError.value = Object.values(errs)[0]),
        onFinish: () => (e.target.value = ''),
    });
};
const deletingFile = ref(null);
const deleteFile = () => router.delete(route('attachments.destroy', deletingFile.value.id), { preserveScroll: true, onFinish: () => (deletingFile.value = null) });
const preview = ref(null);

// --- links ------------------------------------------------------------------------------
const linkType = ref('relates_to');
const linkTypes = [
    { value: 'blocks', label: 'Blocks' },
    { value: 'relates_to', label: 'Relates to' },
    { value: 'duplicates', label: 'Duplicates' },
    { value: 'clones', label: 'Clones' },
];
const addingLink = ref(false);
const linkError = ref(null);
const addLink = (t) =>
    router.post(route('tasks.links.store', key.value), { type: linkType.value, target_id: t.id }, {
        preserveScroll: true,
        onError: (e) => (linkError.value = Object.values(e)[0]),
        onSuccess: () => ((addingLink.value = false), (linkError.value = null)),
    });
const removeLink = (l) => router.delete(route('links.destroy', l.id), { preserveScroll: true });

// --- delete -----------------------------------------------------------------------------
const deleting = ref(false);
const deleteError = ref(null);
const destroy = (withChildren = false) =>
    router.delete(route('tasks.destroy', key.value), { data: { with_children: withChildren }, onError: (e) => (deleteError.value = e.children ?? Object.values(e)[0]) });

// --- activity text ----------------------------------------------------------------------
const fieldName = { title: 'the title', description: 'the description', type: 'the type', priority: 'the priority', parent: 'the parent', sprint: 'the sprint', story_points: 'story points', original_estimate_min: 'the original estimate', remaining_estimate_min: 'the remaining estimate', start_date: 'the start date', due_date: 'the due date', reporter: 'the reporter', resolution_note: 'the resolution note', labels: 'the labels' };
const minutesFields = ['original_estimate_min', 'remaining_estimate_min'];
const describe = (x) => {
    const val = (v) => (minutesFields.includes(x.field) && v !== null ? formatDuration(Number(v)) : v);
    switch (x.event) {
        case 'created': return 'created the task';
        case 'status_changed': return `moved it from ${x.old ?? '—'} to ${x.new ?? '—'}`;
        case 'assigned': return x.new ? `assigned it to ${x.new}${x.old ? ` (was ${x.old})` : ''}` : `unassigned it (was ${x.old})`;
        case 'updated': return x.field === 'description' ? 'edited the description' : `changed ${fieldName[x.field] ?? x.field}${x.old !== null || x.new !== null ? ` from ${val(x.old) ?? '—'} to ${val(x.new) ?? '—'}` : ''}`;
        case 'commented': return 'commented';
        case 'attachment_added': return `attached ${x.new}`;
        case 'attachment_removed': return `removed ${x.old}`;
        case 'linked': return `linked it: ${x.new}`;
        case 'unlinked': return `removed the link: ${x.old}`;
        case 'triage_accepted': return 'accepted the request';
        case 'triage_rejected': return `rejected the request: ${x.new}`;
        case 'triage_info_requested': return 'asked for more information';
        case 'work_logged': return `logged ${formatDuration(Number(x.new))} of work`;
        case 'work_updated': return `changed a work entry to ${formatDuration(Number(x.new))}`;
        case 'work_deleted': return `removed a work entry of ${formatDuration(Number(x.old))}`;
        case 'deleted': return 'deleted the task';
        case 'restored': return 'restored the task';
        default: return x.event;
    }
};
const tab = ref('comments');
const labelColor = (c) => c;

// --- time tracking --------------------------------------------------------------------------
const todayIso = () => new Date().toISOString().slice(0, 10);
const logOpen = ref(false);
const editingLog = ref(null);
const logError = ref('');
const logForm = useForm({ work_date: todayIso(), duration: '', note: '' });

const openLog = (entry = null) => {
    editingLog.value = entry;
    logError.value = '';
    logForm.clearErrors();
    logForm.defaults({ work_date: entry?.date ?? todayIso(), duration: entry ? formatDuration(entry.minutes) : '', note: entry?.note ?? '' }).reset();
    logOpen.value = true;
};
const saveLog = () => {
    const minutes = parseDuration(logForm.duration);
    if (!minutes || Number.isNaN(minutes) || minutes < 1) {
        logError.value = 'Enter the time as 1h 30m, 45m or 1.5h.';
        return;
    }
    logError.value = '';
    const options = { preserveScroll: true, onSuccess: () => (logOpen.value = false) };
    logForm.transform((d) => ({ work_date: d.work_date, minutes, note: d.note }));
    editingLog.value ? logForm.put(route('worklogs.update', editingLog.value.id), options) : logForm.post(route('tasks.worklogs.store', props.task.key), options);
};
const removeLog = (entry) => router.delete(route('worklogs.destroy', entry.id), { preserveScroll: true });
const spentPct = computed(() => (props.task.original_estimate_min ? Math.min(100, Math.round((props.task.time_spent_min / props.task.original_estimate_min) * 100)) : 0));
const overEstimate = computed(() => props.task.original_estimate_min && props.task.time_spent_min > props.task.original_estimate_min);
const rollupPct = computed(() => (props.rollup?.original ? Math.min(100, Math.round((props.rollup.spent / props.rollup.original) * 100)) : 0));
</script>

<template>
    <Head v-if="!inPanel" :title="`${task.key} · ${task.title}`" />

        <!-- Breadcrumbs: project > epic > parent > task -->
        <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-gray-500" aria-label="Breadcrumb">
            <Link :href="route('projects.show', project.id)" class="hover:text-gray-900">{{ project.key }}</Link>
            <template v-for="b in breadcrumbs" :key="b.key">
                <span>/</span>
                <Link :href="route('tasks.show', b.key)" class="flex items-center gap-1 hover:text-gray-900"><TaskBits kind="type" :value="b.type" /> {{ b.key }}</Link>
            </template>
            <span>/</span>
            <span class="font-medium text-gray-800">{{ task.key }}</span>
        </nav>

        <p v-if="project.status === 'archived'" class="mb-4 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">This project is archived, so the task is read-only.</p>
        <p v-if="isInTriage" class="mb-4 flex flex-wrap items-center gap-2 rounded-lg bg-purple-50 px-3 py-2 text-sm text-purple-800">
            This request is waiting in triage.
            <Link v-if="abilities.triage" :href="route('projects.triage.index', project.id)" class="font-medium underline">Review it in the triage queue</Link>
        </p>

        <div :class="inPanel ? 'grid gap-5 md:grid-cols-3' : 'grid gap-6 lg:grid-cols-3'">
            <!-- Main column -->
            <div :class="inPanel ? 'space-y-6 md:col-span-2' : 'space-y-6 lg:col-span-2'">
                <header>
                    <div class="mb-1 flex items-center gap-2"><TaskBits kind="type" :value="task.type" /><span class="font-mono text-xs font-semibold text-gray-500">{{ task.key }}</span></div>
                    <div v-if="editingTitle" class="flex gap-2">
                        <input v-model="titleDraft" type="text" maxlength="255" class="block w-full rounded-lg border-gray-300 text-xl font-semibold shadow-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Task title" autofocus @keydown.enter.prevent="saveTitle" @keydown.escape="editingTitle = false; titleDraft = task.title" @blur="saveTitle" />
                    </div>
                    <h1 v-else class="group flex items-start gap-2 text-xl font-semibold text-gray-900">
                        {{ task.title }}
                        <button v-if="abilities.update" class="mt-1 rounded p-1 text-gray-300 hover:bg-gray-100 hover:text-gray-600 group-hover:text-gray-500" aria-label="Edit title" @click="editingTitle = true"><PencilSquareIcon class="h-4 w-4" /></button>
                    </h1>
                </header>

                <!-- Description -->
                <section>
                    <div class="mb-2 flex items-center justify-between"><h2 class="text-sm font-semibold text-gray-900">Description</h2>
                        <button v-if="abilities.update && !editingDescription" class="text-sm font-medium text-indigo-600 hover:underline" @click="editingDescription = true">Edit</button>
                    </div>
                    <div v-if="editingDescription">
                        <RichTextEditor v-model="descriptionDraft" placeholder="Describe the task…" />
                        <div class="mt-2 flex justify-end gap-2"><Btn size="sm" variant="secondary" @click="editingDescription = false; descriptionDraft = task.description ?? ''">Cancel</Btn><Btn size="sm" @click="saveDescription">Save</Btn></div>
                    </div>
                    <div v-else-if="task.description" class="rich-html text-sm text-gray-800" v-html="task.description" />
                    <p v-else class="text-sm text-gray-400">No description.</p>
                </section>

                <!-- Resolution note -->
                <p v-if="task.resolution_note" class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700"><span class="font-medium">Resolution:</span> {{ task.resolution_note }}</p>

                <!-- Epic progress / children -->
                <section v-if="task.type === 'epic' || children.length || abilities.create_subtask">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">{{ task.type === 'epic' ? 'Work in this epic' : 'Sub-tasks' }} <span class="font-normal text-gray-400">({{ children.length }})</span></h2>
                        <Btn v-if="task.type === 'epic' && abilities.create_child" size="sm" variant="secondary" @click="addChild"><PlusIcon class="h-4 w-4" /> Add work</Btn>
                        <Btn v-else-if="abilities.create_subtask" size="sm" variant="secondary" @click="addSubtask"><PlusIcon class="h-4 w-4" /> Add sub-task</Btn>
                    </div>

                    <div v-if="progress" class="mb-3" :aria-label="`${progress.percent}% complete`">
                        <div class="h-2 overflow-hidden rounded-full bg-gray-200"><div class="h-full bg-green-500 transition-all" :style="{ width: progress.percent + '%' }" /></div>
                        <p class="mt-1 text-xs text-gray-500">{{ progress.percent }}% done ({{ progress.done }} of {{ progress.total }} {{ progress.mode === 'points' ? 'points' : 'tasks' }})</p>
                    </div>

                    <ul v-if="children.length" class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
                        <li v-for="c in children" :key="c.id" class="flex items-center gap-3 px-3 py-2 text-sm">
                            <TaskBits kind="type" :value="c.type" />
                            <Link :href="route('tasks.show', c.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ c.key }}</Link>
                            <span :class="['min-w-0 flex-1 truncate', c.status?.category === 'done' && 'text-gray-400 line-through']">{{ c.title }}</span>
                            <Avatar v-if="c.assignee" :name="c.assignee.name" size="sm" />
                            <TaskBits kind="status" :value="c.status" />
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-400">Nothing here yet.</p>
                </section>

                <!-- Attachments -->
                <section>
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Attachments <span class="font-normal text-gray-400">({{ attachments.length }})</span></h2>
                        <label v-if="abilities.attach" class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"><PaperClipIcon class="h-4 w-4" /> Attach<input type="file" multiple class="sr-only" @change="upload" /></label>
                    </div>
                    <InputError :message="uploadError" />
                    <ul v-if="attachments.length" class="grid gap-3 sm:grid-cols-2">
                        <li v-for="f in attachments" :key="f.id" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-2.5 text-sm">
                            <button v-if="f.is_image" type="button" class="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-gray-100" :aria-label="`Preview ${f.name}`" @click="preview = f"><img :src="f.preview_url" :alt="f.name" class="h-full w-full object-cover" loading="lazy" /></button>
                            <PaperClipIcon v-else class="h-8 w-8 shrink-0 text-gray-300" />
                            <div class="min-w-0 flex-1"><div class="truncate font-medium text-gray-800">{{ f.name }}</div><div class="text-xs text-gray-500">{{ fmtSize(f.size) }} &middot; {{ f.uploader }}</div></div>
                            <a :href="f.url" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" :aria-label="`Download ${f.name}`"><ArrowDownTrayIcon class="h-4 w-4" /></a>
                            <button v-if="f.can_delete" class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Delete ${f.name}`" @click="deletingFile = f"><TrashIcon class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-400">No files.</p>
                </section>

                <!-- Linked tasks -->
                <section>
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Linked tasks <span class="font-normal text-gray-400">({{ links.length }})</span></h2>
                        <button v-if="abilities.link" class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline" @click="addingLink = !addingLink"><LinkIcon class="h-4 w-4" /> Link a task</button>
                    </div>
                    <div v-if="addingLink" class="mb-3 flex flex-wrap items-start gap-2 rounded-xl border border-gray-200 bg-white p-3">
                        <SelectInput v-model="linkType" :options="linkTypes" class="!w-40" aria-label="Link type" />
                        <div class="min-w-[14rem] flex-1"><TaskPicker :exclude="task.id" placeholder="Find a task to link…" @pick="addLink" /></div>
                        <InputError class="w-full" :message="linkError" />
                    </div>
                    <ul v-if="links.length" class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
                        <li v-for="l in links" :key="l.id" class="flex items-center gap-2 px-3 py-2 text-sm">
                            <span class="w-28 shrink-0 text-xs text-gray-500">{{ l.wording }}</span>
                            <Link :href="route('tasks.show', l.task.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ l.task.key }}</Link>
                            <span :class="['min-w-0 flex-1 truncate', l.task.category === 'done' && 'text-gray-400 line-through']">{{ l.task.title }}</span>
                            <Badge color="gray">{{ l.task.status }}</Badge>
                            <button v-if="l.can_delete" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove link" @click="removeLink(l)"><TrashIcon class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-400">No links.</p>
                </section>

                <!-- Comments & history -->
                <section>
                    <div class="mb-3 flex gap-1 border-b border-gray-200" role="tablist">
                        <button role="tab" :aria-selected="tab === 'comments'" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', tab === 'comments' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500']" @click="tab = 'comments'">Comments ({{ comments.length }})</button>
                        <button role="tab" :aria-selected="tab === 'history'" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', tab === 'history' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500']" @click="tab = 'history'">History</button>
                    </div>

                    <div v-if="tab === 'comments'" class="space-y-4">
                        <form v-if="abilities.comment" class="space-y-2" @submit.prevent="addComment">
                            <label class="sr-only" for="new-comment">Add a comment</label>
                            <MentionTextarea id="new-comment" v-model="commentForm.body" :people="options.mentionable" placeholder="Add a comment… type @ to mention a teammate" />
                            <InputError :message="commentForm.errors.body" />
                            <div class="flex items-center justify-between gap-3">
                                <label v-if="abilities.internal_comments" class="flex items-center gap-2 text-sm text-gray-600"><input v-model="commentForm.is_internal" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Internal (hidden from clients)</label>
                                <span v-else class="text-xs text-gray-400">Everyone on the project can read your comment.</span>
                                <Btn type="submit" size="sm" :loading="commentForm.processing" :disabled="!commentForm.body.trim()">Comment</Btn>
                            </div>
                        </form>

                        <ul class="space-y-4">
                            <li v-for="c in comments" :key="c.id" class="flex gap-3">
                                <Avatar :name="c.author.name" :former="c.author.former" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 text-sm"><span class="font-medium text-gray-900">{{ c.author.name }}<template v-if="c.author.former"> (Former)</template></span><span class="text-xs text-gray-400">{{ fmtTime(c.created_at) }}<template v-if="c.edited_at"> &middot; edited</template></span><Badge v-if="c.is_internal" color="yellow">Internal</Badge></div>
                                    <div v-if="editingComment === c.id" class="mt-1 space-y-2">
                                        <MentionTextarea v-model="editForm.body" :people="options.mentionable" />
                                        <InputError :message="editForm.errors.body" />
                                        <div class="flex items-center gap-3"><label v-if="abilities.internal_comments" class="flex items-center gap-2 text-sm text-gray-600"><input v-model="editForm.is_internal" type="checkbox" class="rounded border-gray-300 text-indigo-600" /> Internal</label><span class="ml-auto flex gap-2"><Btn size="sm" variant="secondary" @click="editingComment = null">Cancel</Btn><Btn size="sm" :loading="editForm.processing" @click="saveComment(c)">Save</Btn></span></div>
                                    </div>
                                    <p v-else class="mt-1 whitespace-pre-line break-words text-sm text-gray-800"><template v-for="(s, i) in segments(c.body)" :key="i"><span v-if="s.mention" class="rounded bg-indigo-50 px-1 font-medium text-indigo-700">@{{ s.mention }}</span><template v-else>{{ s.text }}</template></template></p>
                                    <div v-if="editingComment !== c.id && (c.can_edit || c.can_delete)" class="mt-1 flex gap-3 text-xs text-gray-500"><button v-if="c.can_edit" class="hover:text-gray-800" @click="startEdit(c)">Edit</button><button v-if="c.can_delete" class="hover:text-red-600" @click="deletingComment = c">Delete</button></div>
                                </div>
                            </li>
                            <li v-if="!comments.length" class="text-sm text-gray-400">No comments yet.</li>
                        </ul>
                    </div>

                    <ol v-else class="space-y-3">
                        <li v-for="x in activities" :key="x.id" class="flex gap-3 text-sm">
                            <Avatar :name="x.actor ?? 'System'" size="sm" />
                            <div><span class="font-medium text-gray-900">{{ x.actor ?? 'System' }}</span> <span class="text-gray-600">{{ describe(x) }}</span><div class="text-xs text-gray-400">{{ fmtTime(x.created_at) }}</div></div>
                        </li>
                        <li v-if="!activities.length" class="text-sm text-gray-400">No history yet.</li>
                    </ol>
                </section>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-status">Status</label>
                        <SelectInput v-if="abilities.change_status && !isInTriage" id="f-status" :model-value="task.status.id" :options="statusOptions" class="mt-1" @update:model-value="moveTo" />
                        <div v-else class="mt-1"><TaskBits kind="status" :value="task.status" /></div>
                        <p v-if="statusError" class="mt-1 text-xs text-red-600" role="alert">{{ statusError }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-assignee">Assignee</label>
                        <SelectInput v-if="abilities.assign" id="f-assignee" :model-value="task.assignee?.id ?? null" :options="assigneeOptions" class="mt-1" @update:model-value="assign" />
                        <div v-else class="mt-1 flex items-center gap-2"><template v-if="task.assignee"><Avatar :name="task.assignee.name" size="sm" /> {{ task.assignee.name }}</template><span v-else class="text-gray-400">Unassigned</span></div>
                        <button v-if="abilities.assign && myId && task.assignee?.id !== myId" class="mt-1 text-xs font-medium text-indigo-600 hover:underline" @click="assign(myId)">Assign to me</button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-priority">Priority</label>
                            <SelectInput v-if="abilities.update" id="f-priority" :model-value="task.priority" :options="priorityOptions" class="mt-1" @update:model-value="(v) => patch({ priority: v })" />
                            <div v-else class="mt-1"><TaskBits kind="priority" :value="task.priority" /></div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-type">Type</label>
                            <SelectInput v-if="abilities.update" id="f-type" :model-value="task.type" :options="typeOptions" class="mt-1" @update:model-value="(v) => patch({ type: v })" />
                            <div v-else class="mt-1"><TaskBits kind="type" :value="task.type" /></div>
                        </div>
                    </div>

                    <div v-if="project.labels !== null">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">Labels</span>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <template v-if="abilities.update">
                                <button v-for="l in options.labels" :key="l.id" type="button" :aria-pressed="hasLabel(l.id)" :class="['rounded-full border px-2 py-0.5 text-xs font-medium', hasLabel(l.id) ? 'border-transparent text-white' : 'border-gray-300 text-gray-500']" :style="hasLabel(l.id) ? { backgroundColor: labelColor(l.color) } : {}" @click="toggleLabel(l.id)">{{ l.name }}</button>
                                <span v-if="!options.labels.length" class="text-xs text-gray-400">No labels in this project.</span>
                            </template>
                            <template v-else><span v-for="l in task.labels" :key="l.id" class="rounded-full px-2 py-0.5 text-xs font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span><span v-if="!task.labels.length" class="text-gray-400">None</span></template>
                        </div>
                    </div>

                    <div v-if="sprint">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">Sprint</span>
                        <Link :href="route('projects.sprints.index', sprint.project_id)" class="mt-1 inline-block text-indigo-700 hover:underline">{{ sprint.name }}</Link>
                        <span class="ml-1 text-xs capitalize text-gray-400">{{ sprint.status }}</span>
                    </div>

                    <div v-if="parentTypes">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">{{ task.type === 'subtask' ? 'Parent' : 'Epic' }}</span>
                        <div v-if="parent && !changingParent" class="mt-1 flex items-center gap-2"><Link :href="route('tasks.show', parent.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ parent.key }}</Link><span class="truncate">{{ parent.title }}</span></div>
                        <span v-else-if="!changingParent" class="mt-1 block text-gray-400">None</span>
                        <div v-if="abilities.update">
                            <button v-if="!changingParent" class="mt-1 text-xs font-medium text-indigo-600 hover:underline" @click="changingParent = true">{{ parent ? 'Change' : 'Set' }}</button>
                            <div v-else class="mt-1 space-y-1"><TaskPicker :project-id="project.id" :types="parentTypes" :exclude="task.id" @pick="setParent" /><div class="flex gap-3 text-xs"><button v-if="task.type !== 'subtask' && task.parent_id" class="text-red-600 hover:underline" @click="setParent(null)">Remove</button><button class="text-gray-500 hover:underline" @click="changingParent = false">Cancel</button></div></div>
                        </div>
                    </div>
                </section>

                <section class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-start">Start</label>
                            <input v-if="abilities.update" id="f-start" type="date" :value="task.start_date" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="patch({ start_date: $event.target.value || null })" />
                            <div v-else class="mt-1">{{ fmtDate(task.start_date) }}</div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-due">Due</label>
                            <input v-if="abilities.update" id="f-due" type="date" :value="task.due_date" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="patch({ due_date: $event.target.value || null })" />
                            <div v-else class="mt-1">{{ fmtDate(task.due_date) }}</div>
                        </div>
                    </div>

                    <template v-if="abilities.effort">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-points">Story points</label>
                                <input v-if="abilities.update" id="f-points" type="number" min="0" step="0.5" :value="task.story_points" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="savePoints" />
                                <div v-else class="mt-1">{{ task.story_points ?? '—' }}</div>
                            </div>
                            <div><span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">Time spent</span><div class="mt-1.5">{{ formatDuration(task.time_spent_min) || '0m' }}</div></div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-orig">Estimate</label>
                                <input v-if="abilities.update" id="f-orig" v-model="estimateDrafts.original" type="text" placeholder="1h 30m" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="saveEstimate('original_estimate_min', estimateDrafts.original)" />
                                <div v-else class="mt-1">{{ estimateDrafts.original || '—' }}</div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400" for="f-rem">Remaining</label>
                                <input v-if="abilities.update" id="f-rem" v-model="estimateDrafts.remaining" type="text" placeholder="1h" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="saveEstimate('remaining_estimate_min', estimateDrafts.remaining)" />
                                <div v-else class="mt-1">{{ estimateDrafts.remaining || '—' }}</div>
                            </div>
                        </div>
                        <InputError :message="estimateError" />
                    </template>
                </section>

                <section class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Reporter</span>
                        <span class="flex items-center gap-2"><Avatar :name="task.reporter.name" size="sm" /> {{ task.reporter.name }}</span>
                    </div>
                    <div class="flex items-center justify-between text-gray-500"><span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Created</span><span>{{ fmtDate(task.created_at) }}</span></div>
                    <div v-if="task.completed_at" class="flex items-center justify-between text-gray-500"><span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Completed</span><span>{{ fmtDate(task.completed_at) }}</span></div>

                    <div class="border-t border-gray-100 pt-3">
                        <button class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-700 hover:text-indigo-600" @click="toggleWatch">
                            <component :is="isWatching ? EyeSlashIcon : EyeIcon" class="h-4 w-4" /> {{ isWatching ? 'Stop watching' : 'Watch' }}
                        </button>
                        <ul v-if="watchers.length" class="mt-2 flex flex-wrap gap-1.5" aria-label="Watchers">
                            <li v-for="w in watchers" :key="w.id" :title="w.name"><Avatar :name="w.name" size="sm" /></li>
                        </ul>
                        <p v-if="watchers.length" class="mt-1 text-xs text-gray-400">{{ watchers.length }} {{ watchers.length === 1 ? 'watcher' : 'watchers' }}</p>
                    </div>
                </section>

                <section v-if="abilities.effort" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm" aria-label="Time tracking">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Time</h2>
                        <Btn v-if="abilities.log_work" size="sm" @click="openLog()"><PlusIcon class="h-4 w-4" /> Log work</Btn>
                    </div>

                    <div v-if="task.original_estimate_min">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>{{ formatDuration(task.time_spent_min) || '0m' }} spent of {{ formatDuration(task.original_estimate_min) }}</span>
                            <span :class="overEstimate ? 'font-medium text-red-600' : ''">{{ overEstimate ? formatDuration(task.time_spent_min - task.original_estimate_min) + ' over' : (formatDuration(task.remaining_estimate_min ?? 0) || '0m') + ' left' }}</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="spentPct" aria-valuemin="0" aria-valuemax="100">
                            <div :class="['h-full rounded-full', overEstimate ? 'bg-red-500' : 'bg-indigo-500']" :style="{ width: spentPct + '%' }" />
                        </div>
                    </div>
                    <p v-else class="text-gray-500">{{ formatDuration(task.time_spent_min) || '0m' }} spent. No estimate yet.</p>

                    <div v-if="rollup" class="rounded-lg bg-purple-50 p-3">
                        <div class="flex justify-between text-xs text-purple-800">
                            <span>All {{ rollup.tasks }} tasks in this epic</span>
                            <span>{{ formatDuration(rollup.spent) || '0m' }} of {{ formatDuration(rollup.original) || '0m' }}</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full bg-purple-500" :style="{ width: rollupPct + '%' }" /></div>
                    </div>

                    <ul v-if="worklogs.length" class="divide-y divide-gray-100" aria-label="Work log">
                        <li v-for="w in worklogs" :key="w.id" class="py-2">
                            <div class="flex items-center gap-2">
                                <Avatar :name="w.who.name" size="sm" />
                                <span class="min-w-0 flex-1 truncate text-gray-800">{{ w.who.name }}</span>
                                <span class="font-medium text-gray-900">{{ formatDuration(w.minutes) }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-2 pl-8 text-xs text-gray-500">
                                <span>{{ fmtDate(w.date) }}</span>
                                <span v-if="w.locked" class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500" title="The timesheet for this week is submitted">Locked</span>
                                <span v-if="w.can_edit" class="ml-auto flex gap-2">
                                    <button class="hover:text-indigo-600" :aria-label="'Edit entry of ' + w.who.name" @click="openLog(w)">Edit</button>
                                    <button class="hover:text-red-600" :aria-label="'Delete entry of ' + w.who.name" @click="removeLog(w)">Delete</button>
                                </span>
                            </div>
                            <p v-if="w.note" class="mt-0.5 pl-8 text-gray-600">{{ w.note }}</p>
                        </li>
                    </ul>
                </section>

                <Btn v-if="abilities.delete" variant="ghost" size="sm" class="!text-red-600" @click="deleting = true"><TrashIcon class="h-4 w-4" /> Delete task</Btn>
            </aside>
        </div>

        <!-- Log work -->
        <Modal :show="logOpen" max-width="md" @close="logOpen = false">
            <form class="space-y-4 p-6" @submit.prevent="saveLog">
                <h2 class="text-lg font-semibold text-gray-900">{{ editingLog ? 'Edit entry' : 'Log work' }} · {{ task.key }}</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="lw-date">Date</label>
                        <input id="lw-date" v-model="logForm.work_date" type="date" required :max="todayIso()" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="logForm.errors.work_date" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="lw-time">Time spent</label>
                        <input id="lw-time" v-model="logForm.duration" type="text" required placeholder="1h 30m" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="logError || logForm.errors.minutes" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="lw-note">What did you do? <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea id="lw-note" v-model="logForm.note" rows="2" maxlength="500" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError class="mt-1" :message="logForm.errors.note || logForm.errors.task || logForm.errors.work_log" />
                </div>
                <div class="flex justify-end gap-2">
                    <Btn variant="secondary" @click="logOpen = false">Cancel</Btn>
                    <Btn type="submit" :loading="logForm.processing">Save</Btn>
                </div>
            </form>
        </Modal>

        <!-- Close a parent with open sub-tasks -->
        <ConfirmDialog
            :show="!!openChildrenMessage"
            title="Close the sub-tasks too?"
            :message="openChildrenMessage ?? ''"
            confirm-text="Close all"
            @confirm="moveTo(pendingStatus, { close_children: true })"
            @cancel="openChildrenMessage = null; pendingStatus = null"
        />
        <ConfirmDialog :show="!!deletingComment" title="Delete this comment?" message="It disappears from the task." confirm-text="Delete" danger @confirm="deleteComment" @cancel="deletingComment = null" />
        <ConfirmDialog :show="!!deletingFile" title="Remove this file?" :message="deletingFile ? deletingFile.name : ''" confirm-text="Remove" danger @confirm="deleteFile" @cancel="deletingFile = null" />
        <ConfirmDialog :show="deleting" title="Delete this task?" :message="children.length ? `${task.key} and its ${children.length} sub-task(s) will be deleted. You can restore them from the project's deleted tasks.` : `${task.key} will be deleted. You can restore it from the project's deleted tasks.`" confirm-text="Delete" danger @confirm="destroy(children.length > 0)" @cancel="deleting = false">
            <p v-if="deleteError" class="mt-3 text-sm text-red-600">{{ deleteError }}</p>
        </ConfirmDialog>

        <Modal :show="!!preview" max-width="2xl" @close="preview = null"><div v-if="preview" class="p-4"><img :src="preview.preview_url" :alt="preview.name" class="mx-auto max-h-[70vh] rounded-lg" /><p class="mt-2 text-center text-sm text-gray-500">{{ preview.name }}</p></div></Modal>
</template>
