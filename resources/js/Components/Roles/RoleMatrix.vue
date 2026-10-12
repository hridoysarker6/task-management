<script setup>
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import { ClipboardDocumentIcon, LockClosedIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Permission grid (modules x roles) plus role create / edit / copy / delete.
 * Used by Settings > Roles & Permissions and by the System Admin's default template.
 */
const props = defineProps({
    roles: { type: Array, required: true },
    modules: { type: Array, required: true },
    scopes: { type: Array, required: true },
    abilities: { type: Object, required: true }, // { assign, create, edit, delete }
    urls: { type: Object, required: true }, // { matrix, store, update(id), destroy(id) }
});

// --- Matrix state ---------------------------------------------------------------------------
const draft = reactive({}); // roleId -> { data_scope, permissions: string[] }

const reset = () => {
    for (const key of Object.keys(draft)) delete draft[key];
    for (const r of props.roles) draft[r.id] = { data_scope: r.data_scope, permissions: [...r.permissions] };
};
reset();
watch(() => props.roles, reset);

const editable = (role) => props.abilities.assign && !role.is_locked;
const has = (role, name) => role.is_locked || draft[role.id].permissions.includes(name);

const toggle = (role, name) => {
    if (!editable(role)) return;
    const list = draft[role.id].permissions;
    const i = list.indexOf(name);
    i === -1 ? list.push(name) : list.splice(i, 1);
};

const moduleState = (role, module) => {
    const names = module.permissions.map((p) => p.name);
    const held = names.filter((n) => has(role, n)).length;
    return held === 0 ? 'none' : held === names.length ? 'all' : 'some';
};

const toggleModule = (role, module) => {
    if (!editable(role)) return;
    const names = module.permissions.map((p) => p.name);
    const list = draft[role.id].permissions;
    if (moduleState(role, module) === 'all') {
        draft[role.id].permissions = list.filter((n) => !names.includes(n));
    } else {
        draft[role.id].permissions = [...new Set([...list, ...names])];
    }
};

const changedRoles = computed(() =>
    props.roles.filter((r) => {
        if (r.is_locked) return false;
        const d = draft[r.id];
        const before = [...r.permissions].sort().join('|');
        const after = [...d.permissions].sort().join('|');
        return d.data_scope !== r.data_scope || before !== after;
    }),
);

const saving = ref(false);
const save = () => {
    router.put(
        props.urls.matrix,
        { roles: changedRoles.value.map((r) => ({ id: r.id, data_scope: draft[r.id].data_scope, permissions: draft[r.id].permissions })) },
        { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) },
    );
};

// --- Role create / edit / copy / delete ------------------------------------------------------
const blank = { name: '', level: 65, data_scope: 'own', description: '', is_external: false, copy_from: null };
const form = useForm({ ...blank });
const showForm = ref(false);
const editing = ref(null); // role being edited, or null when creating

const openCreate = (copyFrom = null) => {
    editing.value = null;
    form.defaults({ ...blank, copy_from: copyFrom?.id ?? null }).reset();
    if (copyFrom) {
        form.name = `${copyFrom.name} (copy)`;
        form.level = Math.min(copyFrom.level + 1, 99);
        form.data_scope = copyFrom.data_scope;
        form.is_external = copyFrom.is_external;
    }
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (role) => {
    editing.value = role;
    form.defaults({ name: role.name, level: role.level, data_scope: role.data_scope, description: role.description ?? '', is_external: role.is_external, copy_from: null }).reset();
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editing.value ? form.put(props.urls.update(editing.value.id), options) : form.post(props.urls.store, options);
};

const deleting = ref(null);
const deleteError = ref(null);
const confirmDelete = () => {
    router.delete(props.urls.destroy(deleting.value.id), {
        preserveScroll: true,
        onError: (errors) => (deleteError.value = errors.role ?? 'Could not delete this role.'),
        onSuccess: () => (deleting.value = null),
    });
};
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">
                Tick what each role may do. <strong class="font-medium text-gray-700">Data scope</strong> decides whose records a role can see.
                Changes apply immediately after saving.
            </p>
            <div class="flex items-center gap-2">
                <Btn v-if="abilities.create" variant="secondary" @click="openCreate()">
                    <PlusIcon class="h-4 w-4" /> New role
                </Btn>
                <template v-if="abilities.assign">
                    <Btn variant="ghost" :disabled="!changedRoles.length" @click="reset">Discard</Btn>
                    <Btn :disabled="!changedRoles.length" :loading="saving" @click="save">
                        Save changes<span v-if="changedRoles.length"> ({{ changedRoles.length }})</span>
                    </Btn>
                </template>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-auto" style="max-height: 70vh">
                <table class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 top-0 z-30 w-64 min-w-[16rem] border-b border-r border-gray-200 bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Permission
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                class="sticky top-0 z-20 min-w-[9.5rem] border-b border-gray-200 bg-gray-50 px-3 py-3 align-top"
                            >
                                <div class="flex items-center justify-center gap-1 text-gray-900">
                                    <LockClosedIcon v-if="role.is_locked" class="h-3.5 w-3.5 text-gray-400" title="Locked" />
                                    <span class="font-semibold">{{ role.name }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-center gap-1 text-[11px] font-normal text-gray-500">
                                    <span>Level {{ role.level }}</span>
                                    <Badge v-if="role.is_external" color="purple">External</Badge>
                                </div>
                                <div class="mt-2">
                                    <SelectInput
                                        v-model="draft[role.id].data_scope"
                                        :options="scopes"
                                        :disabled="!editable(role)"
                                        class="!py-1 !text-xs"
                                        :aria-label="`Data scope for ${role.name}`"
                                    />
                                </div>
                                <div class="mt-2 flex items-center justify-center gap-0.5">
                                    <span class="mr-1 text-[11px] font-normal text-gray-400">{{ role.members_count }} {{ role.members_count === 1 ? 'person' : 'people' }}</span>
                                    <button v-if="abilities.create" class="rounded p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-700" title="Copy role" @click="openCreate(role)">
                                        <ClipboardDocumentIcon class="h-4 w-4" />
                                    </button>
                                    <button v-if="abilities.edit && !role.is_locked" class="rounded p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-700" title="Edit role" @click="openEdit(role)">
                                        <PencilSquareIcon class="h-4 w-4" />
                                    </button>
                                    <button v-if="abilities.delete && !role.is_locked" class="rounded p-1 text-gray-400 hover:bg-red-100 hover:text-red-600" title="Delete role" @click="deleteError = null; deleting = role">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template v-for="module in modules" :key="module.module">
                            <tr>
                                <td class="sticky left-0 z-10 border-b border-r border-gray-200 bg-gray-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                    {{ module.module }}
                                </td>
                                <td v-for="role in roles" :key="role.id" class="border-b border-gray-200 bg-gray-100 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                        :checked="moduleState(role, module) === 'all'"
                                        :indeterminate.prop="moduleState(role, module) === 'some'"
                                        :disabled="!editable(role)"
                                        :aria-label="`Select all ${module.module} for ${role.name}`"
                                        @change="toggleModule(role, module)"
                                    />
                                </td>
                            </tr>
                            <tr v-for="permission in module.permissions" :key="permission.id" class="group">
                                <td class="sticky left-0 z-10 border-b border-r border-gray-100 bg-white px-4 py-2 group-hover:bg-gray-50">
                                    <div class="text-gray-800">{{ permission.label }}</div>
                                    <div class="font-mono text-[11px] text-gray-400">{{ permission.name }}</div>
                                </td>
                                <td v-for="role in roles" :key="role.id" class="border-b border-gray-100 px-3 py-2 text-center group-hover:bg-gray-50">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                        :checked="has(role, permission.name)"
                                        :disabled="!editable(role)"
                                        :aria-label="`${permission.label} for ${role.name}`"
                                        @change="toggle(role, permission.name)"
                                    />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create / edit role -->
        <Modal :show="showForm" max-width="lg" @close="showForm = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-gray-900">
                    {{ editing ? `Edit ${editing.name}` : form.copy_from ? 'Copy role' : 'New role' }}
                </h2>
                <p v-if="!editing" class="mt-1 text-sm text-gray-500">
                    {{ form.copy_from ? 'The new role starts with the same permissions.' : 'The new role starts with no permissions; tick them in the grid afterwards.' }}
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="role-name">Name</label>
                        <input id="role-name" v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="role-level">Level</label>
                            <input id="role-level" v-model.number="form.level" type="number" min="1" max="99" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p class="mt-1 text-xs text-gray-500">Lower = more senior. A manager must outrank the people reporting to them.</p>
                            <InputError class="mt-1" :message="form.errors.level" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Data scope</label>
                            <SelectInput v-model="form.data_scope" :options="scopes" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.data_scope" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="role-description">Description</label>
                        <input id="role-description" v-model="form.description" type="text" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError class="mt-1" :message="form.errors.description" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="form.is_external" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        External role (client or vendor staff; never in the reporting hierarchy)
                    </label>
                    <InputError :message="form.errors.copy_from" />
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <Btn variant="secondary" @click="showForm = false">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">{{ editing ? 'Save role' : 'Create role' }}</Btn>
                </div>
            </form>
        </Modal>

        <!-- Delete role -->
        <ConfirmDialog
            :show="!!deleting"
            title="Delete this role?"
            :message="deleting ? `“${deleting.name}” will be removed. This only works when nobody holds the role.` : ''"
            confirm-text="Delete role"
            danger
            @confirm="confirmDelete"
            @cancel="deleting = null"
        >
            <p v-if="deleteError" class="mt-3 text-sm text-red-600">{{ deleteError }}</p>
        </ConfirmDialog>
    </div>
</template>
