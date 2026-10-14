<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    departments: { type: Array, required: true },
    people: { type: Array, required: true },
    abilities: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Department' },
    { key: 'head', label: 'Head' },
    { key: 'members_count', label: 'People' },
    { key: 'actions', label: '', class: 'text-right' },
];

const form = useForm({ name: '', code: '', parent_id: null, head_membership_id: null });
const editing = ref(null);
const show = ref(false);

const openCreate = () => {
    editing.value = null;
    form.defaults({ name: '', code: '', parent_id: null, head_membership_id: null }).reset();
    form.clearErrors();
    show.value = true;
};
const openEdit = (d) => {
    editing.value = d;
    form.defaults({ name: d.name, code: d.code ?? '', parent_id: d.parent_id, head_membership_id: d.head_membership_id }).reset();
    form.clearErrors();
    show.value = true;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('departments.update', editing.value.id), options) : form.post(route('departments.store'), options);
};

const parentOptions = computed(() => [
    { value: null, label: 'No parent' },
    ...props.departments.filter((d) => d.id !== editing.value?.id).map((d) => ({ value: d.id, label: d.name })),
]);
const headOptions = computed(() => [{ value: null, label: 'Not assigned' }, ...props.people.map((p) => ({ value: p.id, label: `${p.name} (${p.role})` }))]);

const deleting = ref(null);
const deleteError = ref(null);
const remove = () =>
    router.delete(route('departments.destroy', deleting.value.id), {
        preserveScroll: true,
        onError: (e) => (deleteError.value = e.department ?? 'Could not delete this department.'),
        onSuccess: () => (deleting.value = null),
    });

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head title="Departments" />

    <AppLayout>
        <PageHeader title="Departments" subtitle="Organise people into departments and name a head for each.">
            <template #actions>
                <Btn v-if="abilities.create" @click="openCreate"><PlusIcon class="h-4 w-4" /> New department</Btn>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="departments" empty-text="No departments yet.">
            <template #cell-name="{ row }">
                <div class="font-medium text-gray-900">{{ row.name }}<span v-if="row.code" class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-600">{{ row.code }}</span></div>
                <div v-if="row.parent" class="text-xs text-gray-500">Under {{ row.parent }}</div>
            </template>
            <template #cell-head="{ row }">{{ row.head ?? '—' }}</template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <button v-if="abilities.edit" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Edit" :aria-label="`Edit ${row.name}`" @click="openEdit(row)"><PencilSquareIcon class="h-4 w-4" /></button>
                    <button v-if="abilities.delete" class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Delete" :aria-label="`Delete ${row.name}`" @click="deleteError = null; deleting = row"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </template>
        </DataTable>

        <Modal :show="show" max-width="lg" @close="show = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-gray-900">{{ editing ? 'Edit department' : 'New department' }}</h2>
                <div class="mt-4 space-y-4">
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700" for="dept-name">Name</label>
                            <input id="dept-name" v-model="form.name" type="text" required autofocus :class="input" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="dept-code">Code</label>
                            <input id="dept-code" v-model="form.code" type="text" :class="input" />
                            <InputError class="mt-1" :message="form.errors.code" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="dept-parent">Parent department</label>
                        <SelectInput id="dept-parent" v-model="form.parent_id" :options="parentOptions" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.parent_id" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="dept-head">Head of department</label>
                        <SelectInput id="dept-head" v-model="form.head_membership_id" :options="headOptions" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.head_membership_id" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <Btn variant="secondary" @click="show = false">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">Save</Btn>
                </div>
            </form>
        </Modal>

        <ConfirmDialog :show="!!deleting" title="Delete this department?" :message="deleting ? `“${deleting.name}” will be removed.` : ''" confirm-text="Delete" danger @confirm="remove" @cancel="deleting = null">
            <p v-if="deleteError" class="mt-3 text-sm text-red-600">{{ deleteError }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>
