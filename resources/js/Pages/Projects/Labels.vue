<script setup>
import InputError from '@/Components/InputError.vue';
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    labels: { type: Array, required: true },
});

const canManage = props.project.abilities.manage_labels;
const palette = ['#ef4444', '#f59e0b', '#22c55e', '#06b6d4', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#6b7280'];

const form = useForm({ name: '', color: '#6366f1' });
const editing = ref(null);

const startEdit = (l) => {
    editing.value = l;
    form.defaults({ name: l.name, color: l.color }).reset();
    form.clearErrors();
};
const cancel = () => {
    editing.value = null;
    form.defaults({ name: '', color: '#6366f1' }).reset();
    form.clearErrors();
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: cancel };
    editing.value ? form.put(route('projects.labels.update', [props.project.id, editing.value.id]), options) : form.post(route('projects.labels.store', props.project.id), options);
};

const deleting = ref(null);
const remove = () => router.delete(route('projects.labels.destroy', [props.project.id, deleting.value.id]), { preserveScroll: true, onFinish: () => (deleting.value = null) });
</script>

<template>
    <Head :title="`${project.key} · Labels`" />

    <AppLayout>
        <ProjectHeader :project="project" active="labels" />

        <form v-if="canManage" class="mb-6 max-w-xl rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="submit">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">{{ editing ? 'Edit label' : 'New label' }}</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[10rem] flex-1">
                    <label class="block text-sm font-medium text-gray-700" for="label-name">Name</label>
                    <input id="label-name" v-model="form.name" type="text" required maxlength="50" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                    <span class="block text-sm font-medium text-gray-700">Colour</span>
                    <div class="mt-2 flex items-center gap-1.5">
                        <button
                            v-for="c in palette"
                            :key="c"
                            type="button"
                            :aria-label="`Colour ${c}`"
                            :aria-pressed="form.color === c"
                            :class="['h-6 w-6 rounded-full ring-offset-1', form.color === c ? 'ring-2 ring-gray-900' : '']"
                            :style="{ backgroundColor: c }"
                            @click="form.color = c"
                        />
                    </div>
                </div>
                <div class="flex gap-2">
                    <Btn v-if="editing" variant="ghost" @click="cancel">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">{{ editing ? 'Save' : 'Add' }}</Btn>
                </div>
            </div>
            <InputError class="mt-2" :message="form.errors.name || form.errors.color || form.errors.project" />
        </form>

        <EmptyState v-if="!labels.length" title="No labels yet" description="Labels help you group and filter tasks, for example Frontend, Payments or Urgent." />

        <ul v-else class="max-w-xl divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            <li v-for="l in labels" :key="l.id" class="flex items-center gap-3 px-4 py-2.5">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
                <div v-if="canManage" class="ml-auto flex gap-1">
                    <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" :aria-label="`Edit ${l.name}`" @click="startEdit(l)"><PencilSquareIcon class="h-4 w-4" /></button>
                    <button class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Delete ${l.name}`" @click="deleting = l"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </li>
        </ul>

        <ConfirmDialog :show="!!deleting" title="Delete this label?" :message="deleting ? `“${deleting.name}” will be removed from every task that uses it.` : ''" confirm-text="Delete" danger @confirm="remove" @cancel="deleting = null" />
    </AppLayout>
</template>
