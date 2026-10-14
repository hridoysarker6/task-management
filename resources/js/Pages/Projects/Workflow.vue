<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownIcon, ArrowUpIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    statuses: { type: Array, required: true },
    transitions: { type: Array, required: true },
    categories: { type: Array, required: true },
    permissions: { type: Array, required: true },
});

const canManage = computed(() => props.project.abilities.manage_workflow);
const categoryLabel = { triage: 'Triage', todo: 'To do', in_progress: 'In progress', done: 'Done', cancelled: 'Cancelled' };
const categoryOptions = props.categories.map((c) => ({ value: c, label: categoryLabel[c] }));
const palette = ['#6b7280', '#3b82f6', '#06b6d4', '#22c55e', '#f59e0b', '#ef4444', '#a855f7', '#ec4899'];

// --- Statuses ---------------------------------------------------------------------------------
const form = useForm({ name: '', category: 'in_progress', color: '#3b82f6', show_on_board: true, is_initial: false });
const editing = ref(null);
const show = ref(false);

const openCreate = () => {
    editing.value = null;
    form.defaults({ name: '', category: 'in_progress', color: '#3b82f6', show_on_board: true, is_initial: false }).reset();
    form.clearErrors();
    show.value = true;
};
const openEdit = (s) => {
    editing.value = s;
    form.defaults({ name: s.name, category: s.category, color: s.color, show_on_board: s.show_on_board, is_initial: s.is_initial }).reset();
    form.clearErrors();
    show.value = true;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('projects.workflow.statuses.update', [props.project.id, editing.value.id]), options) : form.post(route('projects.workflow.statuses.store', props.project.id), options);
};

const move = (index, delta) => {
    const ids = props.statuses.map((s) => s.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + delta, 0, id);
    router.put(route('projects.workflow.statuses.reorder', props.project.id), { order: ids }, { preserveScroll: true });
};

const deleting = ref(null);
const deleteError = ref(null);
const remove = () =>
    router.delete(route('projects.workflow.statuses.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onError: (e) => (deleteError.value = e.status ?? 'Could not delete this status.'),
        onSuccess: () => (deleting.value = null),
    });

// --- Allowed moves -----------------------------------------------------------------------------
// With no rules every move is allowed. Once a project restricts moves, only the ticked ones are.
const key = (from, to) => `${from}>${to}`;
const moves = reactive(new Map());
const restricted = ref(false);

const load = () => {
    moves.clear();
    props.transitions.forEach((t) => moves.set(key(t.from_status_id, t.to_status_id), t.required_permission ?? ''));
    restricted.value = props.transitions.length > 0;
};
load();
watch(() => [props.transitions, props.statuses], load);

const setRestricted = (value) => {
    restricted.value = value;
    if (value && moves.size === 0) {
        // Start from "everything allowed" so the admin only has to untick what should not be possible.
        props.statuses.forEach((a) => props.statuses.forEach((b) => a.id !== b.id && moves.set(key(a.id, b.id), '')));
    }
};
const toggle = (from, to) => {
    const k = key(from, to);
    moves.has(k) ? moves.delete(k) : moves.set(k, '');
};

const selected = computed(() =>
    [...moves.entries()].map(([k, permission]) => {
        const [from, to] = k.split('>').map(Number);
        return { from_status_id: from, to_status_id: to, required_permission: permission || null };
    }),
);
const name = (id) => props.statuses.find((s) => s.id === id)?.name;
const savingMoves = ref(false);
const saveMoves = () =>
    router.put(route('projects.workflow.transitions', props.project.id), { moves: restricted.value ? selected.value : [] }, {
        preserveScroll: true,
        onStart: () => (savingMoves.value = true),
        onFinish: () => (savingMoves.value = false),
    });
const permissionOptions = [{ value: '', label: 'No special permission' }, ...props.permissions.map((p) => ({ value: p, label: p }))];
</script>

<template>
    <Head :title="`${project.key} · Workflow`" />

    <AppLayout>
        <ProjectHeader :project="project" active="workflow" />

        <div class="space-y-8">
            <section>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">Statuses</h2>
                        <p class="text-sm text-gray-500">The columns of the board, in order. The category decides how progress is counted.</p>
                    </div>
                    <Btn v-if="canManage" size="sm" @click="openCreate"><PlusIcon class="h-4 w-4" /> Add status</Btn>
                </div>

                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <li v-for="(s, i) in statuses" :key="s.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: s.color }" />
                        <span class="font-medium text-gray-900">{{ s.name }}</span>
                        <Badge color="gray">{{ categoryLabel[s.category] }}</Badge>
                        <Badge v-if="s.is_initial" color="indigo">Starting status</Badge>
                        <span v-if="!s.show_on_board" class="text-xs text-gray-400">Not a board column</span>
                        <span v-if="s.task_count" class="text-xs text-gray-400">{{ s.task_count }} tasks</span>

                        <div v-if="canManage" class="ml-auto flex items-center gap-0.5">
                            <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30" :disabled="i === 0" :aria-label="`Move ${s.name} up`" @click="move(i, -1)"><ArrowUpIcon class="h-4 w-4" /></button>
                            <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30" :disabled="i === statuses.length - 1" :aria-label="`Move ${s.name} down`" @click="move(i, 1)"><ArrowDownIcon class="h-4 w-4" /></button>
                            <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" :aria-label="`Edit ${s.name}`" @click="openEdit(s)"><PencilSquareIcon class="h-4 w-4" /></button>
                            <button class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Delete ${s.name}`" @click="deleteError = null; deleting = s"><TrashIcon class="h-4 w-4" /></button>
                        </div>
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="text-sm font-semibold text-gray-900">Allowed moves</h2>
                <p class="mb-3 text-sm text-gray-500">By default a task can move between any two statuses. Restrict this if your process needs a strict path.</p>

                <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input type="radio" :checked="!restricted" :disabled="!canManage" class="text-indigo-600 focus:ring-indigo-500" @change="setRestricted(false)" /> Any move is allowed</label>
                        <label class="flex items-center gap-2"><input type="radio" :checked="restricted" :disabled="!canManage" class="text-indigo-600 focus:ring-indigo-500" @change="setRestricted(true)" /> Only the moves ticked below</label>
                    </div>

                    <template v-if="restricted">
                        <div class="overflow-x-auto">
                            <table class="min-w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">From &darr; / To &rarr;</th>
                                        <th v-for="t in statuses" :key="t.id" class="px-2 py-2 text-center text-xs font-medium text-gray-600"><span class="inline-block max-w-[5rem] truncate align-bottom" :title="t.name">{{ t.name }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="f in statuses" :key="f.id">
                                        <th class="whitespace-nowrap border-t border-gray-100 px-2 py-1.5 text-left text-xs font-medium text-gray-600">{{ f.name }}</th>
                                        <td v-for="t in statuses" :key="t.id" class="border-t border-gray-100 px-2 py-1.5 text-center">
                                            <span v-if="f.id === t.id" class="text-gray-300">&ndash;</span>
                                            <input
                                                v-else
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                :checked="moves.has(key(f.id, t.id))"
                                                :disabled="!canManage"
                                                :aria-label="`Allow ${f.name} to ${t.name}`"
                                                @change="toggle(f.id, t.id)"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="selected.length">
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Optional: moves that need a permission</h3>
                            <ul class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                <li v-for="m in selected" :key="`${m.from_status_id}>${m.to_status_id}`" class="flex items-center gap-2 text-xs text-gray-600">
                                    <span class="w-40 shrink-0 truncate">{{ name(m.from_status_id) }} &rarr; {{ name(m.to_status_id) }}</span>
                                    <SelectInput :model-value="moves.get(key(m.from_status_id, m.to_status_id))" :options="permissionOptions" :disabled="!canManage" class="!py-0.5 !text-xs" @update:model-value="(v) => moves.set(key(m.from_status_id, m.to_status_id), v)" />
                                </li>
                            </ul>
                        </div>
                        <p v-else class="text-sm text-amber-700">With no moves ticked nobody could move a task. Tick at least one, or choose "Any move is allowed".</p>
                    </template>

                    <div v-if="canManage" class="flex justify-end"><Btn :loading="savingMoves" :disabled="restricted && !selected.length" @click="saveMoves">Save</Btn></div>
                </div>
            </section>
        </div>

        <Modal :show="show" max-width="lg" @close="show = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-gray-900">{{ editing ? 'Edit status' : 'New status' }}</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="s-name">Name</label>
                        <input id="s-name" v-model="form.name" type="text" required maxlength="50" autofocus class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="s-cat">Category</label>
                        <SelectInput id="s-cat" v-model="form.category" :options="categoryOptions" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500">Progress counts "Done" as finished, and ignores "Cancelled" and "Triage".</p>
                        <InputError class="mt-1" :message="form.errors.category" />
                    </div>
                    <div>
                        <span class="block text-sm font-medium text-gray-700">Colour</span>
                        <div class="mt-2 flex items-center gap-1.5">
                            <button v-for="c in palette" :key="c" type="button" :aria-label="`Colour ${c}`" :aria-pressed="form.color === c" :class="['h-6 w-6 rounded-full ring-offset-1', form.color === c ? 'ring-2 ring-gray-900' : '']" :style="{ backgroundColor: c }" @click="form.color = c" />
                        </div>
                        <InputError class="mt-1" :message="form.errors.color" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="form.show_on_board" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Show as a board column</label>
                    <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="form.is_initial" type="checkbox" :disabled="editing?.is_initial" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Starting status for new tasks</label>
                    <InputError :message="form.errors.is_initial || form.errors.project" />
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <Btn variant="secondary" @click="show = false">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">Save</Btn>
                </div>
            </form>
        </Modal>

        <ConfirmDialog :show="!!deleting" title="Delete this status?" :message="deleting ? `“${deleting.name}” and its allowed moves will be removed.` : ''" confirm-text="Delete" danger @confirm="remove" @cancel="deleting = null">
            <p v-if="deleteError" class="mt-3 text-sm text-red-600">{{ deleteError }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>
