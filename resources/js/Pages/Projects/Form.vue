<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Create and edit share this page: `project` is null when creating.
const props = defineProps({
    project: { type: Object, default: null },
    departments: { type: Array, required: true },
    people: { type: Array, required: true },
});

const editing = !!props.project;
const p = props.project;

const form = useForm({
    name: p?.name ?? '',
    key: p?.key ?? '',
    description: p?.description ?? '',
    department_id: p?.department_id ?? null,
    manager_membership_id: p?.manager_membership_id ?? null,
    start_date: p?.start_date ?? '',
    end_date: p?.end_date ?? '',
});

// Suggest the key from the name (initials, or the first letters of a single word) until it is edited by hand.
const keyTouched = ref(editing);
const suggest = (name) => {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const raw = words.length > 1 ? words.map((w) => w[0]).join('') : (words[0] ?? '').slice(0, 4);
    return raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
};
watch(() => form.name, (n) => {
    if (!keyTouched.value) form.key = suggest(n);
});

const departmentOptions = computed(() => [{ value: null, label: 'No department' }, ...props.departments.map((d) => ({ value: d.id, label: d.name }))]);
const peopleOptions = computed(() => [{ value: null, label: 'No project manager yet' }, ...props.people.map((x) => ({ value: x.id, label: `${x.name} (${x.role})` }))]);

const submit = () => {
    const data = (d) => ({ ...d, start_date: d.start_date || null, end_date: d.end_date || null, key: d.key.toUpperCase() });
    if (editing) {
        form.transform(data).put(route('projects.update', p.id));
    } else {
        form.transform(data).post(route('projects.store'));
    }
};

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head :title="editing ? `Edit ${p.name}` : 'New project'" />

    <AppLayout>
        <PageHeader :title="editing ? `Edit ${p.name}` : 'New project'" subtitle="Task keys are built from the project key, for example ECP-42." />

        <form class="max-w-2xl space-y-4 rounded-xl border border-gray-200 bg-white p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700" for="name">Name</label>
                    <input id="name" v-model="form.name" type="text" required autofocus :class="input" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="key">Key</label>
                    <input
                        id="key"
                        v-model="form.key"
                        type="text"
                        required
                        maxlength="10"
                        :disabled="p?.key_locked"
                        :class="[input, 'font-mono uppercase', p?.key_locked && 'bg-gray-100']"
                        @input="keyTouched = true"
                    />
                    <p v-if="p?.key_locked" class="mt-1 text-xs text-gray-500">Locked: the project already has tasks.</p>
                    <InputError class="mt-1" :message="form.errors.key" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="department">Department</label>
                    <SelectInput id="department" v-model="form.department_id" :options="departmentOptions" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.department_id" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="manager">Project manager</label>
                    <SelectInput id="manager" v-model="form.manager_membership_id" :options="peopleOptions" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.manager_membership_id" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="start_date">Start date</label>
                    <input id="start_date" v-model="form.start_date" type="date" :class="input" />
                    <InputError class="mt-1" :message="form.errors.start_date" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="end_date">End date</label>
                    <input id="end_date" v-model="form.end_date" type="date" :class="input" />
                    <InputError class="mt-1" :message="form.errors.end_date" />
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700" for="description">Description</label>
                <textarea id="description" v-model="form.description" rows="4" :class="input" />
                <InputError class="mt-1" :message="form.errors.description" />
            </div>

            <InputError :message="form.errors.project" />

            <div class="flex items-center justify-between pt-2">
                <Link :href="editing ? route('projects.show', p.id) : route('projects.index')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                <Btn type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create project' }}</Btn>
            </div>
        </form>
    </AppLayout>
</template>
