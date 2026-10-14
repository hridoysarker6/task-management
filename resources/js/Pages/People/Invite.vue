<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    email: { type: String, default: '' },
    roles: { type: Array, required: true },
    departments: { type: Array, required: true },
    managers: { type: Array, required: true },
});

const form = useForm({
    email: props.email,
    name: '',
    role_id: null,
    department_id: null,
    reports_to_id: null,
    designation: '',
    employee_code: '',
});

const role = computed(() => props.roles.find((r) => r.id === form.role_id));
const external = computed(() => role.value?.is_external ?? false);

// Only people who outrank the chosen role can be its manager (checked again on the server).
const managerOptions = computed(() => [
    { value: null, label: 'No manager yet' },
    ...props.managers.filter((m) => !role.value || m.level < role.value.level).map((m) => ({ value: m.id, label: `${m.name} (${m.role})` })),
]);

const roleOptions = computed(() => [
    { value: null, label: 'Select a role…' },
    ...props.roles.map((r) => ({ value: r.id, label: r.is_external ? `${r.name} (external)` : r.name })),
]);
const departmentOptions = computed(() => [{ value: null, label: 'No department' }, ...props.departments.map((d) => ({ value: d.id, label: d.name }))]);

const submit = () => form.transform((d) => ({ ...d, reports_to_id: external.value ? null : d.reports_to_id })).post(route('people.invitations.store'));

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head title="Invite someone" />

    <AppLayout>
        <PageHeader title="Invite someone" subtitle="They get an email with a link that is valid for 7 days." />

        <form class="max-w-2xl space-y-4 rounded-xl border border-gray-200 bg-white p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="email">Email</label>
                    <input id="email" v-model="form.email" type="email" required autofocus :class="input" />
                    <InputError class="mt-1" :message="form.errors.email" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="name">Name <span class="font-normal text-gray-400">(optional)</span></label>
                    <input id="name" v-model="form.name" type="text" :class="input" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="role">Role</label>
                    <SelectInput id="role" v-model="form.role_id" :options="roleOptions" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.role_id" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="department">Department</label>
                    <SelectInput id="department" v-model="form.department_id" :options="departmentOptions" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.department_id" />
                </div>

                <div v-if="!external" class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700" for="manager">Reports to</label>
                    <SelectInput id="manager" v-model="form.reports_to_id" :options="managerOptions" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500">The manager must have a higher rank than the role you choose.</p>
                    <InputError class="mt-1" :message="form.errors.reports_to_id" />
                </div>
                <p v-else class="rounded-lg bg-purple-50 px-3 py-2 text-sm text-purple-800 sm:col-span-2">
                    This is an external role: the person is never part of the reporting hierarchy and only sees the projects they are added to.
                </p>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="designation">Designation</label>
                    <input id="designation" v-model="form.designation" type="text" :class="input" />
                    <InputError class="mt-1" :message="form.errors.designation" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="employee_code">Employee code</label>
                    <input id="employee_code" v-model="form.employee_code" type="text" :class="input" />
                    <InputError class="mt-1" :message="form.errors.employee_code" />
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <Link :href="route('people.index')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                <Btn type="submit" :loading="form.processing">Send invitation</Btn>
            </div>
        </form>
    </AppLayout>
</template>
